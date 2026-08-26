<?php

/**
 * Function-level WordPress stubs. Define-once-guarded so loading the
 * plugin source multiple times in a Pest run doesn't redeclare.
 */
if (! function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        return $GLOBALS['__pitchbar_test_options'][$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option($key, $value): bool
    {
        $GLOBALS['__pitchbar_test_options'][$key] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option($key): bool
    {
        unset($GLOBALS['__pitchbar_test_options'][$key]);

        return true;
    }
}

if (! function_exists('add_option')) {
    function add_option($key, $value): bool
    {
        if (! isset($GLOBALS['__pitchbar_test_options'][$key])) {
            $GLOBALS['__pitchbar_test_options'][$key] = $value;
        }

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient($key)
    {
        $entry = $GLOBALS['__pitchbar_test_transients'][$key] ?? null;
        if ($entry === null) {
            return false;
        }
        if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
            return false;
        }

        return $entry['value'];
    }
}

if (! function_exists('set_transient')) {
    function set_transient($key, $value, int $ttl = 0): bool
    {
        $GLOBALS['__pitchbar_test_transients'][$key] = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient($key): bool
    {
        unset($GLOBALS['__pitchbar_test_transients'][$key]);

        return true;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($value, int $options = 0)
    {
        return json_encode($value, $options);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string
    {
        return is_string($value) ? trim(strip_tags($value)) : '';
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email($value): string
    {
        return is_string($value) ? filter_var($value, FILTER_SANITIZE_EMAIL) : '';
    }
}

if (! function_exists('is_email')) {
    function is_email($value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = '/'): string
    {
        return 'https://shop.test'.$path;
    }
}

if (! function_exists('error_log')) {
    // PHP already has error_log; never redefine.
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return $GLOBALS['__pitchbar_test_current_user_can'] ?? true;
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user()
    {
        return $GLOBALS['__pitchbar_test_current_user'] ?? null;
    }
}

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return ! empty($GLOBALS['__pitchbar_test_current_user']);
    }
}

if (! function_exists('add_action')) {
    function add_action(...$args): bool
    {
        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(...$args): bool
    {
        return true;
    }
}

if (! function_exists('register_rest_route')) {
    function register_rest_route(...$args): bool
    {
        return true;
    }
}

if (! function_exists('wp_remote_post')) {
    function wp_remote_post(...$args): array
    {
        return ['response' => ['code' => 200], 'body' => '{}'];
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r)
    {
        return $r['response']['code'] ?? 0;
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r)
    {
        return (string) ($r['body'] ?? '');
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($v): bool
    {
        return $v instanceof WP_Error;
    }
}

if (! function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $f): string
    {
        return 'https://wp.test/wp-content/plugins/pitchbar/';
    }
}

if (! function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $f): string
    {
        return dirname($f).'/';
    }
}

if (! function_exists('plugin_basename')) {
    function plugin_basename(string $f): string
    {
        return basename(dirname($f)).'/'.basename($f);
    }
}

if (! function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(...$args): bool
    {
        return true;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text);
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $text): string
    {
        return $text;
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $text): string
    {
        return $text;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $s): string
    {
        return strip_tags($s);
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $value) ?? '');
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action)
    {
        return $nonce !== '' ? 1 : false;
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return 'nonce_'.$action;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false)
    {
        $store = $GLOBALS['__pitchbar_test_postmeta'][$postId] ?? [];
        if ($key === '') {
            return $store;
        }

        return $store[$key] ?? '';
    }
}

if (! function_exists('get_post')) {
    function get_post($id = null)
    {
        if ($id === null) {
            return $GLOBALS['__pitchbar_test_current_post'] ?? null;
        }

        return $GLOBALS['__pitchbar_test_posts'][(int) $id] ?? null;
    }
}

if (! function_exists('get_posts')) {
    function get_posts(array $args = []): array
    {
        $type = $args['post_type'] ?? 'post';
        $store = $GLOBALS['__pitchbar_test_posts_by_type'][$type] ?? [];
        $limit = isset($args['numberposts']) ? (int) $args['numberposts'] : count($store);
        if ($limit < 0) {
            $limit = count($store);
        }

        return array_slice($store, 0, $limit);
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink($post): string
    {
        $id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;

        return 'https://wp.test/?p='.$id;
    }
}

if (! function_exists('get_object_taxonomies')) {
    function get_object_taxonomies(string $type, string $output = 'names'): array
    {
        return [];
    }
}

if (! function_exists('get_the_terms')) {
    function get_the_terms($post, string $taxonomy)
    {
        return [];
    }
}

if (! function_exists('mysql2date')) {
    function mysql2date(string $format, string $date, bool $translate = true): string
    {
        $ts = strtotime($date) ?: time();

        return gmdate('c', $ts);
    }
}

if (! function_exists('get_locale')) {
    function get_locale(): string
    {
        return 'en_US';
    }
}

if (! function_exists('do_blocks')) {
    function do_blocks(string $content): string
    {
        return $content;
    }
}

if (! function_exists('do_shortcode')) {
    function do_shortcode(string $content): string
    {
        return $content;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args)
    {
        if (! isset($GLOBALS['__pitchbar_test_filters'][$hook])) {
            return $value;
        }
        foreach ($GLOBALS['__pitchbar_test_filters'][$hook] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}

if (! function_exists('setup_postdata')) {
    function setup_postdata($post): bool
    {
        $GLOBALS['__pitchbar_test_setup_postdata_calls'][] = isset($post->ID) ? (int) $post->ID : 0;

        return true;
    }
}

if (! function_exists('wp_reset_postdata')) {
    function wp_reset_postdata(): bool
    {
        $GLOBALS['__pitchbar_test_reset_postdata_calls'] = ($GLOBALS['__pitchbar_test_reset_postdata_calls'] ?? 0) + 1;

        return true;
    }
}

if (! function_exists('did_action')) {
    function did_action(string $action): int
    {
        return (int) ($GLOBALS['__pitchbar_test_did_actions'][$action] ?? 0);
    }
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = [])
    {
        return $GLOBALS['__pitchbar_test_scheduled'][$hook] ?? false;
    }
}

if (! function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $GLOBALS['__pitchbar_test_scheduled'][$hook] = $timestamp;
        $GLOBALS['__pitchbar_test_scheduled_calls'][] = ['hook' => $hook, 'timestamp' => $timestamp];

        return true;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): int
    {
        unset($GLOBALS['__pitchbar_test_scheduled'][$hook]);

        return 1;
    }
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public string $post_title = '';

        public string $post_content = '';

        public string $post_excerpt = '';

        public string $post_status = 'publish';

        public string $post_type = 'post';

        public string $post_modified = '';

        public string $post_modified_gmt = '';
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;

        public string $message;

        public function __construct(string $code = '', string $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;

        public int $status;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, string> */
        public array $headers = [];

        public string $body = '';

        public string $route = '';

        public function get_header(string $name): string
        {
            return $this->headers[strtolower($name)] ?? ($this->headers[$name] ?? '');
        }

        public function set_header(string $name, string $value): void
        {
            $this->headers[strtolower($name)] = $value;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function set_body(string $value): void
        {
            $this->body = $value;
        }

        public function get_route(): string
        {
            return $this->route;
        }
    }
}

if (! class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const CREATABLE = 'POST';
    }
}
