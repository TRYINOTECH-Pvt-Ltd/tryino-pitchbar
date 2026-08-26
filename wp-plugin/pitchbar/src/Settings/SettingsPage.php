<?php

namespace Pitchbar\Settings;

use Pitchbar\Api\PitchbarClient;
use Pitchbar\Plugin;
use Pitchbar\Support\Capabilities;
use Pitchbar\Support\Logger;
use Pitchbar\Sync\PostSyncer;
use Pitchbar\Sync\ProductSyncer;

/**
 * Renders the Settings → Pitchbar admin page and handles the AJAX
 * connection-test endpoint. All form posts go through WP's Settings
 * API, which handles the nonce + redirect flow for us.
 */
final class SettingsPage
{
    public const MENU_SLUG = 'pitchbar';

    public const OPTION_GROUP = 'pitchbar_settings_group';

    public const AJAX_TEST_ACTION = 'pitchbar_test_connection';

    public const AJAX_SYNC_ACTION = 'pitchbar_run_full_sync';

    public const AJAX_PRODUCT_SYNC_ACTION = 'pitchbar_run_product_sync';

    public const SCHEDULED_SYNC_HOOK = 'pitchbar_run_full_sync_event';

    public const SCHEDULED_PRODUCT_SYNC_HOOK = 'pitchbar_run_product_sync_event';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'maybeShowConfigureNotice']);
        add_action('wp_ajax_'.self::AJAX_TEST_ACTION, [$this, 'handleTestConnection']);
        add_action('wp_ajax_'.self::AJAX_SYNC_ACTION, [$this, 'handleRunFullSync']);
        add_action(self::SCHEDULED_SYNC_HOOK, [$this, 'runScheduledSync']);
        add_action('wp_ajax_'.self::AJAX_PRODUCT_SYNC_ACTION, [$this, 'handleRunProductSync']);
        add_action(self::SCHEDULED_PRODUCT_SYNC_HOOK, [$this, 'runScheduledProductSync']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('Pitchbar', 'pitchbar'),
            __('Pitchbar', 'pitchbar'),
            Capabilities::REQUIRED_CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            PITCHBAR_OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [new SettingsValidator, 'sanitize'],
                'default' => [],
            ]
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_'.self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'pitchbar-admin',
            PITCHBAR_PLUGIN_URL.'assets/admin.css',
            [],
            PITCHBAR_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'pitchbar-admin',
            PITCHBAR_PLUGIN_URL.'assets/admin.js',
            [],
            PITCHBAR_PLUGIN_VERSION,
            true
        );

        wp_localize_script('pitchbar-admin', 'PitchbarAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Capabilities::AJAX_NONCE_ACTION),
            'action' => self::AJAX_TEST_ACTION,
            'syncAction' => self::AJAX_SYNC_ACTION,
            'productSyncAction' => self::AJAX_PRODUCT_SYNC_ACTION,
            'wooActive' => $this->isWooActive(),
            'i18n' => [
                'testing' => __('Testing connection…', 'pitchbar'),
                'success' => __('Connected. Pick the agent you want to attach below.', 'pitchbar'),
                'failure' => __('Connection failed.', 'pitchbar'),
                'noAgents' => __('No agents found in this workspace. Create one in your Pitchbar admin and try again.', 'pitchbar'),
                'syncing' => __('Syncing posts… this may take a minute on large sites.', 'pitchbar'),
                'syncDone' => __('Sync complete.', 'pitchbar'),
                'syncFailed' => __('Sync failed.', 'pitchbar'),
                'syncingProducts' => __('Syncing products… this may take a minute on large catalogs.', 'pitchbar'),
                'productSyncDone' => __('Product sync complete.', 'pitchbar'),
                'productSyncFailed' => __('Product sync failed.', 'pitchbar'),
                'continuesInBackground' => __('large site detected, the rest is continuing in the background.', 'pitchbar'),
                'showDetails' => __('Show details', 'pitchbar'),
                'hideDetails' => __('Hide details', 'pitchbar'),
            ],
        ]);
    }

    public function maybeShowConfigureNotice(): void
    {
        if (! Capabilities::canManage()) {
            return;
        }
        $configured = Plugin::instance()->isConfigured();
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $onSettingsScreen = $screen !== null && isset($screen->id) && $screen->id === 'settings_page_'.self::MENU_SLUG;

        if (! $configured) {
            if ($onSettingsScreen) {
                return;
            }
            $url = admin_url('options-general.php?page='.self::MENU_SLUG);
            printf(
                '<div class="notice notice-info"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('Pitchbar', 'pitchbar'),
                esc_html__('is installed but not configured yet.', 'pitchbar'),
                esc_url($url),
                esc_html__('Open Settings → Pitchbar to connect.', 'pitchbar')
            );

            return;
        }

        // Show a soft progress notice while a chunked sync is mid-run
        // so the admin knows a background WP-Cron tick is still going.
        if (function_exists('get_transient')) {
            $postResume = get_transient(PostSyncer::RESUME_TRANSIENT);
            $productResume = get_transient(ProductSyncer::RESUME_TRANSIENT);
            if (is_array($postResume) || is_array($productResume)) {
                $resumedKinds = [];
                if (is_array($postResume)) {
                    $resumedKinds[] = __('posts', 'pitchbar');
                }
                if (is_array($productResume)) {
                    $resumedKinds[] = __('products', 'pitchbar');
                }
                printf(
                    '<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
                    esc_html__('Pitchbar', 'pitchbar'),
                    sprintf(
                        /* translators: %s: kinds list like "posts, products" */
                        esc_html__('is finishing a large-site sync in the background (%s). The widget already works; new content shows up once this completes.', 'pitchbar'),
                        esc_html(implode(', ', $resumedKinds))
                    )
                );
            }
        }
    }

    public function render(): void
    {
        Capabilities::abortIfCannotManage();

        $settings = Plugin::instance()->settings();
        $workspaceName = (string) ($settings['workspace_name'] ?? '');
        $availablePostTypes = $this->availablePostTypes();
        $enabledTypes = (array) ($settings['enabled_post_types'] ?? ['post', 'page']);

        ?>
        <div class="wrap pitchbar-settings">
            <h1><?php echo esc_html__('Pitchbar', 'pitchbar'); ?></h1>
            <p class="description"><?php echo esc_html__('Connect this WordPress site to a Pitchbar workspace and embed the chat widget on every public page.', 'pitchbar'); ?></p>

            <?php settings_errors('pitchbar_settings'); ?>

            <form method="post" action="options.php" id="pitchbar-settings-form">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <h2><?php echo esc_html__('Connection', 'pitchbar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="pitchbar_base_url"><?php echo esc_html__('Pitchbar base URL', 'pitchbar'); ?></label>
                        </th>
                        <td>
                            <input
                                type="url"
                                name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[base_url]"
                                id="pitchbar_base_url"
                                value="<?php echo esc_attr((string) $settings['base_url']); ?>"
                                class="regular-text"
                                placeholder="https://app.pitchbar.example"
                                required
                            />
                            <p class="description"><?php echo esc_html__('The URL of your Pitchbar workspace.', 'pitchbar'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pitchbar_api_token"><?php echo esc_html__('API token', 'pitchbar'); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[api_token]"
                                id="pitchbar_api_token"
                                value="<?php echo esc_attr((string) $settings['api_token']); ?>"
                                class="regular-text"
                                autocomplete="off"
                                placeholder="pbar_…"
                                required
                            />
                            <p class="description">
                                <?php echo esc_html__('Create one in your Pitchbar admin under Settings → API tokens. The token is shown once at creation.', 'pitchbar'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" id="pitchbar-test-connection" class="button button-secondary">
                                <?php echo esc_html__('Test connection', 'pitchbar'); ?>
                            </button>
                            <span id="pitchbar-test-result" class="pitchbar-test-result" aria-live="polite"></span>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Agent', 'pitchbar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="pitchbar_agent_id"><?php echo esc_html__('Attached agent', 'pitchbar'); ?></label>
                        </th>
                        <td>
                            <select name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[agent_id]" id="pitchbar_agent_id">
                                <?php if ((string) $settings['agent_id'] !== '') { ?>
                                    <option value="<?php echo esc_attr((string) $settings['agent_id']); ?>" selected>
                                        <?php echo esc_html((string) $settings['agent_id']); ?>
                                    </option>
                                <?php } else { ?>
                                    <option value=""><?php echo esc_html__('— Run Test connection first —', 'pitchbar'); ?></option>
                                <?php } ?>
                            </select>
                            <?php if ($workspaceName !== '') { ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: workspace name */
                                        esc_html__('Connected to workspace: %s', 'pitchbar'),
                                        '<strong>'.esc_html($workspaceName).'</strong>'
                                    );
                                ?>
                                </p>
                            <?php } ?>
                            <input
                                type="hidden"
                                name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[workspace_id]"
                                id="pitchbar_workspace_id"
                                value="<?php echo esc_attr((string) $settings['workspace_id']); ?>"
                            />
                            <input
                                type="hidden"
                                name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[workspace_name]"
                                id="pitchbar_workspace_name"
                                value="<?php echo esc_attr($workspaceName); ?>"
                            />
                            <input
                                type="hidden"
                                name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[shopper_signing_secret]"
                                id="pitchbar_shopper_signing_secret"
                                value="<?php echo esc_attr((string) $settings['shopper_signing_secret']); ?>"
                            />
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Widget display', 'pitchbar'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enabled', 'pitchbar'); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[enabled]"
                                    value="1"
                                    <?php checked(! empty($settings['enabled'])); ?>
                                />
                                <?php echo esc_html__('Inject the Pitchbar widget on the front end', 'pitchbar'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Post types', 'pitchbar'); ?></th>
                        <td>
                            <?php foreach ($availablePostTypes as $type => $label) { ?>
                                <label class="pitchbar-checkbox">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(PITCHBAR_OPTION_KEY); ?>[enabled_post_types][]"
                                        value="<?php echo esc_attr($type); ?>"
                                        <?php checked(in_array($type, $enabledTypes, true)); ?>
                                    />
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php } ?>
                            <p class="description">
                                <?php echo esc_html__('Choose which content types load the widget. Singular pages of unchecked types stay widget-free.', 'pitchbar'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php $this->renderSyncPanel($settings); ?>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function renderSyncPanel(array $settings): void
    {
        if (! Plugin::instance()->isConfigured()) {
            return;
        }
        ?>
        <hr style="margin: 30px 0;" />
        <h2><?php echo esc_html__('Knowledge sync', 'pitchbar'); ?></h2>
        <p class="description" style="max-width: 720px;">
            <?php echo esc_html__('Push every public post and page of the post types you enabled above to Pitchbar so the chat widget can answer from them. Unchanged posts are skipped automatically; this is safe to re-run.', 'pitchbar'); ?>
        </p>
        <p>
            <button type="button" id="pitchbar-run-sync" class="button button-primary">
                <?php echo esc_html__('Sync posts now', 'pitchbar'); ?>
            </button>
            <span id="pitchbar-sync-result" class="pitchbar-test-result" aria-live="polite"></span>
        </p>

        <?php if ($this->isWooActive()) { ?>
            <h2 style="margin-top: 28px;"><?php echo esc_html__('WooCommerce products', 'pitchbar'); ?></h2>
            <p class="description" style="max-width: 720px;">
                <?php echo esc_html__('Push your WooCommerce catalog (simple, variable, grouped, and external products) to Pitchbar. The agent automatically switches to the ecommerce vertical preset so it can emit product cards in chat responses.', 'pitchbar'); ?>
            </p>
            <p>
                <button type="button" id="pitchbar-run-product-sync" class="button button-primary">
                    <?php echo esc_html__('Sync products now', 'pitchbar'); ?>
                </button>
                <span id="pitchbar-product-sync-result" class="pitchbar-test-result" aria-live="polite"></span>
            </p>
        <?php } ?>
        <?php
    }

    public function handleTestConnection(): void
    {
        Capabilities::abortIfCannotManage();

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if (! Capabilities::verifyAjaxNonce($nonce)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'pitchbar')], 403);
        }

        $baseUrl = isset($_POST['base_url']) ? esc_url_raw(wp_unslash((string) $_POST['base_url'])) : '';
        $token = isset($_POST['api_token']) ? sanitize_text_field(wp_unslash((string) $_POST['api_token'])) : '';

        if ($baseUrl === '' || $token === '') {
            wp_send_json_error(['message' => __('Enter both a base URL and an API token, then try again.', 'pitchbar')], 422);
        }

        $client = new PitchbarClient($baseUrl, $token);
        $result = $client->handshake([
            'site_url' => home_url('/'),
            'plugin_version' => PITCHBAR_PLUGIN_VERSION,
            'woocommerce_active' => $this->isWooActive(),
            'wordpress_version' => get_bloginfo('version'),
        ]);

        if (! $result['ok']) {
            Logger::warn('Handshake failed', [
                'status' => $result['status'],
                'error' => $result['error'],
                'diag' => $result['diag'] ?? null,
            ]);

            // Build the most useful single-line summary we can. The
            // admin sees this in the inline error panel; the diag
            // block goes to the collapsible "Show details" view in
            // the same panel so they can copy/paste the raw body if
            // they file an issue.
            $summary = $this->synthesizeFailureMessage($result);

            wp_send_json_error([
                'message' => $summary,
                'status' => $result['status'],
                'diag' => $result['diag'] ?? [],
            ], 200);
        }

        wp_send_json_success([
            'workspace' => $result['data']['workspace'] ?? null,
            'agents' => $result['data']['agents'] ?? [],
            'recommended_site_type' => $result['data']['recommended_site_type'] ?? null,
            'shopper_signing_secret' => $result['data']['token']['shopper_signing_secret'] ?? null,
        ]);
    }

    public function handleRunFullSync(): void
    {
        Capabilities::abortIfCannotManage();

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if (! Capabilities::verifyAjaxNonce($nonce)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'pitchbar')], 403);
        }

        if (! Plugin::instance()->isConfigured()) {
            wp_send_json_error(['message' => __('Configure the plugin before running a sync.', 'pitchbar')], 422);
        }

        @set_time_limit(120);
        $syncer = new PostSyncer;
        $result = $syncer->runFullSync();

        if (! $result['ok']) {
            wp_send_json_error([
                'message' => $result['errors'][0] ?? __('Sync failed.', 'pitchbar'),
                'detail' => $result,
            ], 200);
        }

        wp_send_json_success($result);
    }

    public function runScheduledSync(): void
    {
        if (! Plugin::instance()->isConfigured()) {
            return;
        }
        $syncer = new PostSyncer;
        $result = $syncer->runFullSync();
        if (! $result['ok']) {
            Logger::warn('Scheduled sync had errors', $result);
        }
    }

    public function handleRunProductSync(): void
    {
        Capabilities::abortIfCannotManage();

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if (! Capabilities::verifyAjaxNonce($nonce)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'pitchbar')], 403);
        }

        if (! Plugin::instance()->isConfigured()) {
            wp_send_json_error(['message' => __('Configure the plugin before running a sync.', 'pitchbar')], 422);
        }

        if (! class_exists('WooCommerce')) {
            wp_send_json_error(['message' => __('WooCommerce is not active.', 'pitchbar')], 422);
        }

        @set_time_limit(180);
        $syncer = new ProductSyncer;
        $result = $syncer->runFullSync();

        if (! $result['ok']) {
            wp_send_json_error([
                'message' => $result['errors'][0] ?? __('Product sync failed.', 'pitchbar'),
                'detail' => $result,
            ], 200);
        }

        wp_send_json_success($result);
    }

    public function runScheduledProductSync(): void
    {
        if (! Plugin::instance()->isConfigured() || ! class_exists('WooCommerce')) {
            return;
        }
        $syncer = new ProductSyncer;
        $result = $syncer->runFullSync();
        if (! $result['ok']) {
            Logger::warn('Scheduled product sync had errors', $result);
        }
    }

    /**
     * @return array<string, string> post-type slug => label
     */
    private function availablePostTypes(): array
    {
        $types = ['post' => __('Posts', 'pitchbar'), 'page' => __('Pages', 'pitchbar')];

        $public = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($public as $slug => $obj) {
            $label = isset($obj->labels->name) ? (string) $obj->labels->name : (string) $slug;
            $types[$slug] = $label;
        }

        return $types;
    }

    private function isWooActive(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Compose a one-line failure summary the admin can act on.
     * Prioritises the most specific signal we have:
     *
     *   1. Transport code (cURL error, DNS failure, SSL handshake) —
     *      "Connection failed" really means "the request never
     *      reached Pitchbar", so we say so explicitly.
     *   2. HTTP status mapped to plain English (401/403/404/500/502).
     *   3. Raw error message from the upstream JSON envelope.
     *
     * @param  array{ok: bool, status: int, error: string|null, diag?: array<string, mixed>}  $result
     */
    private function synthesizeFailureMessage(array $result): string
    {
        $status = (int) ($result['status'] ?? 0);
        $error = (string) ($result['error'] ?? '');
        $diag = isset($result['diag']) && is_array($result['diag']) ? $result['diag'] : [];
        $transport = (string) ($diag['transport_code'] ?? '');

        // Transport-level: never reached Pitchbar.
        if ($status === 0) {
            if ($transport === 'http_request_failed' || str_contains($error, 'cURL error')) {
                return sprintf(
                    /* translators: %s: cURL / transport error message */
                    __('Could not reach %1$s — %2$s', 'pitchbar'),
                    (string) ($diag['url'] ?? __('the Pitchbar URL', 'pitchbar')),
                    $error
                );
            }

            return $error !== ''
                ? $error
                : __('Could not reach Pitchbar. Check that the base URL is correct and that your server can make outbound HTTPS requests.', 'pitchbar');
        }

        // HTTP-level — map known statuses to advice the admin can act on.
        $hint = '';
        switch ($status) {
            case 401:
                $hint = __('The API token is invalid or revoked. Reissue one in your Pitchbar workspace under Settings → API tokens.', 'pitchbar');
                break;
            case 403:
                $hint = __('The API token does not have the wp:integration ability. Recreate the token with that scope.', 'pitchbar');
                break;
            case 404:
                $hint = __('Pitchbar is reachable but the /api/v1/wp/handshake route is missing. Check that the Pitchbar app is fully deployed and migrations ran.', 'pitchbar');
                break;
            case 419:
                $hint = __('Pitchbar rejected the request as a CSRF mismatch — your base URL should point to the API host, not a marketing redirect.', 'pitchbar');
                break;
            case 429:
                $hint = __('Pitchbar is rate-limiting this token. Wait a minute and try again.', 'pitchbar');
                break;
            case 500:
            case 502:
            case 503:
                $hint = __('Pitchbar returned a server error. Tail storage/logs/laravel.log on the Pitchbar host for the stack trace.', 'pitchbar');
                break;
        }

        $line = sprintf(
            /* translators: 1: HTTP status, 2: error from upstream */
            __('Pitchbar HTTP %1$d — %2$s', 'pitchbar'),
            $status,
            $error !== '' ? $error : __('no error message in response', 'pitchbar')
        );
        if ($hint !== '') {
            $line .= ' '.$hint;
        }

        return $line;
    }
}
