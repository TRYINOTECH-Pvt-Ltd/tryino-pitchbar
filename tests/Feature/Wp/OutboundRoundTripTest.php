<?php

use App\Events\Leads\LeadCapturedEvent;
use App\Listeners\Leads\PushLeadToWordPress;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Source;
use App\Models\Visitor;
use App\Models\WorkspaceApiToken;
use App\Services\Tools\Tools\LookupOrderClient;
use App\Services\Tools\Tools\LookupOrderTool;
use App\Services\Widget\WidgetJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pitchbar\Support\Hmac;

uses(RefreshDatabase::class);

/**
 * Battle-test: Pitchbar's outbound calls to the WordPress plugin
 * carry signatures the plugin's `Pitchbar\Support\Hmac::verify`
 * accepts. We don't have a real WP install in CI; instead we
 * intercept the outbound request with Http::fake, then re-verify
 * the request body using the plugin's helper loaded in-process.
 *
 * If the two HMAC implementations drift (ts format, body byte
 * handling, header parser), this test catches it before real
 * traffic ever hits a plugin.
 */
beforeEach(function () {
    if (! class_exists('Pitchbar\\Support\\Hmac')) {
        require_once dirname(__DIR__, 2).'/../wp-plugin/pitchbar/src/Support/Hmac.php';
    }
});

test('LookupOrderTool signs the outbound body with a header the plugin Hmac::verify accepts', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'ecommerce']);

    $secret = Str::random(48);
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => $secret,
    ]);

    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
        'last_synced_at' => now(),
    ]);

    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'attribution' => [
            'shopper' => ['wp_user_id' => '500', 'email_hash' => '', 'source' => 'wordpress'],
        ],
    ]);

    $capturedHeader = null;
    $capturedBody = null;

    Http::fake([
        'https://shop.example.com/wp-json/pitchbar/v1/orders/lookup' => function ($request) use (&$capturedHeader, &$capturedBody) {
            $capturedHeader = $request->header('X-Pitchbar-Signature')[0] ?? null;
            $capturedBody = (string) $request->body();

            return Http::response(['data' => ['orders' => [], 'count' => 0]], 200);
        },
    ]);

    $tool = new LookupOrderTool(new LookupOrderClient);
    $out = $tool->execute(['limit' => 3], $agent, ['conversation' => $conversation]);

    expect($out['result']['count'])->toBe(0);
    expect($capturedHeader)->not()->toBeNull('Outbound request did not carry an X-Pitchbar-Signature header.');
    expect($capturedBody)->not()->toBeNull();

    // Now verify the signature using the PLUGIN's helper as a real WP
    // install would. If this fails the two HMAC implementations have
    // drifted and the plugin will reject every Pitchbar callback.
    expect(Hmac::verify($capturedHeader, $secret, $capturedBody))
        ->toBeTrue('Plugin Hmac::verify rejected the signature Pitchbar sent.');

    // Body must carry the visitor's wp_user_id so the plugin can scope
    // wc_get_orders() to the right customer.
    expect($capturedBody)->toContain('"wp_user_id":"500"');
});

test('CouponApplyController proxy signs the outbound body the plugin will accept', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'ecommerce']);

    $secret = Str::random(48);
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => $secret,
    ]);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
        'last_synced_at' => now(),
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    $jwt = app(WidgetJwt::class)
        ->issue($agent->id, $visitor->id, $conversation->id)['token'];

    $capturedHeader = null;
    $capturedBody = null;

    Http::fake([
        'https://shop.example.com/wp-json/pitchbar/v1/cart/coupon' => function ($request) use (&$capturedHeader, &$capturedBody) {
            $capturedHeader = $request->header('X-Pitchbar-Signature')[0] ?? null;
            $capturedBody = (string) $request->body();

            return Http::response(['data' => ['applied' => false, 'pending' => true]], 200);
        },
    ]);

    // The widget Origin re-check middleware requires the request's
    // Origin to match the agent's allowed_origins. AgentFactory
    // defaults that to `https://example.com`; passing the matching
    // header keeps this Wp-folder test green now that every
    // bearer-token widget endpoint enforces Origin.
    $this->postJson('/api/v1/widget/coupon/apply', ['code' => 'WELCOME10'], [
        'Authorization' => 'Bearer '.$jwt,
        'Origin' => 'https://example.com',
    ])->assertOk();

    expect($capturedHeader)->not()->toBeNull();
    expect(Hmac::verify($capturedHeader, $secret, $capturedBody))->toBeTrue();
    expect($capturedBody)->toContain('"code":"WELCOME10"');
});

test('PushLeadToWordPress signs the outbound body the plugin will accept', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $secret = Str::random(48);
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => $secret,
    ]);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
        'last_synced_at' => now(),
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'shopper@example.com',
        'name' => 'V',
        'phone' => '+1',
        'fields' => [],
        'status' => 'new',
    ]);

    $capturedHeader = null;
    $capturedBody = null;

    Http::fake([
        'https://shop.example.com/wp-json/pitchbar/v1/leads' => function ($request) use (&$capturedHeader, &$capturedBody) {
            $capturedHeader = $request->header('X-Pitchbar-Signature')[0] ?? null;
            $capturedBody = (string) $request->body();

            return Http::response(['data' => ['user_id' => 1]], 200);
        },
    ]);

    $event = LeadCapturedEvent::fromLead($lead, (string) $workspace->id, 'Agent');
    (new PushLeadToWordPress)->handle($event);

    expect($capturedHeader)->not()->toBeNull();
    expect(Hmac::verify($capturedHeader, $secret, $capturedBody))->toBeTrue();
    expect($capturedBody)->toContain('"email":"shopper@example.com"');
});
