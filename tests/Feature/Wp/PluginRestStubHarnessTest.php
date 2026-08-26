<?php

use App\Support\HmacSignature;
use Pitchbar\Plugin;
use Pitchbar\Rest\OrderLookupController;
use Pitchbar\Widget\ShopperToken;
use Tests\Support\WordPressStubs;

/**
 * Battle-test: instantiate the plugin's REST controllers inside a
 * stubbed WordPress environment and exercise the HMAC verify path.
 *
 * Catches: header parser regressions, secret-resolution drift, and
 * the response shape that the Pitchbar server unwraps after a real
 * HTTP round-trip.
 *
 * NOT a substitute for a real WP install — WC-specific code paths
 * (WC_Order, wc_create_new_customer) are stubbed out via skip
 * branches inside each controller.
 */
beforeEach(function () {
    require_once dirname(__DIR__, 2).'/Support/WordPressStubs.php';
    WordPressStubs::install();
    WordPressStubs::resetOptions();

    // Plugin autoloader: same hand-rolled spl_autoload_register the
    // plugin's pitchbar.php sets up, scoped to the in-tree source.
    $pluginDir = dirname(__DIR__, 2).'/../wp-plugin/pitchbar/';
    spl_autoload_register(function ($class) use ($pluginDir) {
        if (strpos($class, 'Pitchbar\\') !== 0) {
            return;
        }
        $relative = substr($class, strlen('Pitchbar\\'));
        $path = $pluginDir.'src/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
        if (is_readable($path)) {
            require_once $path;
        }
    });

    // Plugin singleton caches settings per request; nuke between
    // tests so each scenario sees the wp_options state it just set.
    if (class_exists('Pitchbar\\Plugin')) {
        $reflection = new ReflectionClass(Plugin::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
});

test('Plugin RestController verifyOrReject accepts a body the server signs', function () {
    $secret = 'sek_'.bin2hex(random_bytes(8));
    WordPressStubs::setOption('pitchbar_settings', [
        'shopper_signing_secret' => $secret,
        'api_token' => 'pbar_x',
        'base_url' => 'https://app.test',
        'agent_id' => 'agent-id',
    ]);

    $body = '{"wp_user_id":42,"limit":5}';
    $header = HmacSignature::sign($secret, $body);

    $request = new WP_REST_Request;
    $request->set_body($body);
    $request->set_header('X-Pitchbar-Signature', $header);

    $controller = new OrderLookupController;
    $reflect = new ReflectionMethod($controller, 'verifyOrReject');
    $reflect->setAccessible(true);
    $result = $reflect->invoke($controller, $request);

    // verifyOrReject returns the raw body on success, a WP_REST_Response on failure.
    expect($result)->toBe($body);
});

test('Plugin RestController rejects a missing signature', function () {
    WordPressStubs::setOption('pitchbar_settings', [
        'shopper_signing_secret' => 'whatever',
    ]);

    $request = new WP_REST_Request;
    $request->set_body('{"a":1}');

    $controller = new OrderLookupController;
    $reflect = new ReflectionMethod($controller, 'verifyOrReject');
    $reflect->setAccessible(true);
    /** @var WP_REST_Response $result */
    $result = $reflect->invoke($controller, $request);

    expect($result)->toBeInstanceOf(WP_REST_Response::class);
    expect($result->get_status())->toBe(401);
    $data = $result->get_data();
    expect($data['error']['code'])->toBe('missing_signature');
});

test('Plugin RestController rejects when shopper_signing_secret is empty', function () {
    WordPressStubs::setOption('pitchbar_settings', ['shopper_signing_secret' => '']);

    $request = new WP_REST_Request;
    $request->set_body('{"a":1}');
    $request->set_header('X-Pitchbar-Signature', 't=1,v1='.str_repeat('a', 64));

    $controller = new OrderLookupController;
    $reflect = new ReflectionMethod($controller, 'verifyOrReject');
    $reflect->setAccessible(true);
    /** @var WP_REST_Response $result */
    $result = $reflect->invoke($controller, $request);

    expect($result->get_status())->toBe(401);
    expect($result->get_data()['error']['code'])->toBe('plugin_unconfigured');
});

test('Plugin RestController rejects a signature that does not match', function () {
    WordPressStubs::setOption('pitchbar_settings', ['shopper_signing_secret' => 'sek']);

    $request = new WP_REST_Request;
    $request->set_body('{"a":1}');
    $forgedHeader = HmacSignature::sign('different-secret', '{"a":1}');
    $request->set_header('X-Pitchbar-Signature', $forgedHeader);

    $controller = new OrderLookupController;
    $reflect = new ReflectionMethod($controller, 'verifyOrReject');
    $reflect->setAccessible(true);
    /** @var WP_REST_Response $result */
    $result = $reflect->invoke($controller, $request);

    expect($result->get_status())->toBe(401);
    expect($result->get_data()['error']['code'])->toBe('signature_mismatch');
});

test('OrderLookupController returns woocommerce_inactive when WC class missing', function () {
    $secret = 'sek_'.bin2hex(random_bytes(8));
    WordPressStubs::setOption('pitchbar_settings', ['shopper_signing_secret' => $secret]);

    // Body well-formed + signature good — but WooCommerce class
    // doesn't exist in the stub harness, so the controller bails
    // gracefully with a note instead of fatal'ing on WC_Order.
    $body = '{"wp_user_id":1,"limit":3}';
    $request = new WP_REST_Request;
    $request->set_body($body);
    $request->set_header('X-Pitchbar-Signature', HmacSignature::sign($secret, $body));

    /** @var WP_REST_Response $response */
    $response = (new OrderLookupController)->handle($request);

    expect($response->get_status())->toBe(200);
    expect($response->get_data()['data']['note'] ?? null)->toBe('woocommerce_inactive');
});

test('Plugin Widget\\ShopperToken issues a token Pitchbar can verify', function () {
    $secret = 'sek_'.bin2hex(random_bytes(8));
    WordPressStubs::setOption('pitchbar_settings', ['shopper_signing_secret' => $secret]);
    $GLOBALS['__pitchbar_test_current_user'] = (object) [
        'ID' => 77,
        'user_email' => 'Shopper@Example.COM',
    ];

    $token = ShopperToken::maybeIssueForCurrentUser();
    expect($token)->not()->toBe('');

    $claims = App\Services\Widget\ShopperToken::verifyWithSecret($token, $secret);
    expect($claims)->not()->toBeNull();
    expect($claims['wp_user_id'])->toBe('77');
    // Email hash should be sha256 of lowercased email
    expect($claims['email_hash'])->toBe(hash('sha256', 'shopper@example.com'));
});

test('Plugin Widget\\ShopperToken returns empty when not logged in', function () {
    $GLOBALS['__pitchbar_test_current_user'] = null;
    WordPressStubs::setOption('pitchbar_settings', ['shopper_signing_secret' => 'secret']);

    expect(ShopperToken::maybeIssueForCurrentUser())->toBe('');
});

test('Plugin Widget\\ShopperToken returns empty when signing secret missing', function () {
    $GLOBALS['__pitchbar_test_current_user'] = (object) ['ID' => 1, 'user_email' => 'x@y.z'];
    WordPressStubs::setOption('pitchbar_settings', ['shopper_signing_secret' => '']);

    expect(ShopperToken::maybeIssueForCurrentUser())->toBe('');
});
