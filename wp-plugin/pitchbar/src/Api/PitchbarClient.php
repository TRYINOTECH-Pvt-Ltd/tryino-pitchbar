<?php

namespace Pitchbar\Api;

/**
 * Outbound HTTP client. Targets the Pitchbar `/api/v1/wp/*` surface
 * documented at {base_url}/documentation/wordpress-integration.
 *
 * All requests carry `Authorization: Bearer <api_token>`. Mutating
 * calls additionally sign the JSON body with HMAC-SHA256 using the
 * token as the shared secret, matching `App\Support\HmacSignature`
 * on the server side.
 */
final class PitchbarClient
{
    private const DEFAULT_TIMEOUT = 8;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null, diag: array<string, mixed>}
     */
    public function handshake(array $payload): array
    {
        return $this->post('/api/v1/wp/handshake', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function postsSync(array $payload): array
    {
        return $this->post('/api/v1/wp/posts/sync', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function postsChanged(array $payload): array
    {
        return $this->post('/api/v1/wp/posts/changed', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function productsSync(array $payload): array
    {
        return $this->post('/api/v1/wp/products/sync', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function productsChanged(array $payload): array
    {
        return $this->post('/api/v1/wp/products/changed', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function couponsSync(array $payload): array
    {
        return $this->post('/api/v1/wp/coupons/sync', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null, diag: array<string, mixed>}
     */
    private function post(string $path, array $payload): array
    {
        $url = $this->baseUrl.$path;
        $body = wp_json_encode($payload);
        if ($body === false) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => __('Failed to encode request body.', 'pitchbar')
                    .' ('.__('check that your server\'s PHP JSON extension is enabled', 'pitchbar').')',
                'diag' => $this->diag($url, 0, '', '', ''),
            ];
        }

        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Pitchbar-Signature' => $this->signBody($body),
            'User-Agent' => 'Pitchbar-WP/'.PITCHBAR_PLUGIN_VERSION.'; '.home_url('/'),
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => self::DEFAULT_TIMEOUT,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            // Transport-level failure: WordPress' wp_remote_post wraps
            // the underlying cURL / Streams error in a WP_Error. Surface
            // every hint we can — the code (e.g. `http_request_failed`)
            // plus the human message ("cURL error 28: Operation timed
            // out…") so the admin sees exactly which network leg broke.
            $code = (string) $response->get_error_code();
            $message = (string) $response->get_error_message();
            $combined = $code !== '' && $code !== 'error' ? '['.$code.'] '.$message : $message;

            return [
                'ok' => false,
                'status' => 0,
                'data' => [],
                'error' => $combined !== '' ? $combined : __('Transport failure with no message from WordPress.', 'pitchbar'),
                'diag' => $this->diag($url, 0, $combined, '', $code),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return [
                'ok' => true,
                'status' => $status,
                'data' => isset($data['data']) && is_array($data['data']) ? $data['data'] : $data,
                'error' => null,
                'diag' => $this->diag($url, $status, '', $raw, ''),
            ];
        }

        $error = $this->extractError($data, $status);

        return [
            'ok' => false,
            'status' => $status,
            'data' => $data,
            'error' => $error,
            'diag' => $this->diag($url, $status, $error, $raw, ''),
        ];
    }

    /**
     * Capture the bits the admin needs to see when "Test connection"
     * fails — without leaking the bearer token. Kept tiny so the
     * AJAX response stays under PHP's default 1MB serialization budget.
     *
     * @return array<string, mixed>
     */
    private function diag(string $url, int $status, string $error, string $rawBody, string $transportCode): array
    {
        // Cap the raw body so a 5MB HTML error page from the host's
        // reverse proxy doesn't blow the AJAX response.
        $excerpt = mb_substr($rawBody, 0, 800);

        return [
            'url' => $url,
            'status' => $status,
            'transport_code' => $transportCode,
            'error' => $error,
            'body_excerpt' => $excerpt,
            'plugin_version' => defined('PITCHBAR_PLUGIN_VERSION') ? PITCHBAR_PLUGIN_VERSION : '',
        ];
    }

    private function signBody(string $body): string
    {
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, $this->token);

        return 't='.$ts.',v1='.$sig;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractError(array $data, int $status): string
    {
        if (isset($data['error']) && is_array($data['error'])) {
            $msg = isset($data['error']['message']) ? (string) $data['error']['message'] : '';
            $code = isset($data['error']['code']) ? (string) $data['error']['code'] : '';
            if ($msg !== '' && $code !== '') {
                return $msg.' ('.$code.')';
            }
            if ($msg !== '') {
                return $msg;
            }
        }

        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }

        /* translators: %d: HTTP status code */
        return sprintf(__('Pitchbar responded with HTTP %d.', 'pitchbar'), $status);
    }
}
