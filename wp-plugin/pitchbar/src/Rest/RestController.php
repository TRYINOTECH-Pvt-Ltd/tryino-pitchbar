<?php

namespace Pitchbar\Rest;

use Pitchbar\Plugin;
use Pitchbar\Support\Hmac;
use Pitchbar\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Abstract base for any pitchbar/v1/* REST endpoint the plugin
 * exposes. Provides:
 *
 *  - HMAC verification against the workspace's `shopper_signing_secret`.
 *    Pitchbar never holds the bearer plaintext, so any Pitchbar →
 *    plugin call signs with this per-token plaintext secret instead.
 *    The plugin stores it in `wp_options` after handshake.
 *  - JSON success / error shaping that matches the Pitchbar server's
 *    response convention ({ "data": ... } or { "error": ... }).
 */
abstract class RestController
{
    public const NAMESPACE = 'pitchbar/v1';

    /**
     * Subclasses register their concrete WP_REST routes here.
     */
    abstract public function register(): void;

    /**
     * Verifies the X-Pitchbar-Signature header against the stored
     * shopper_signing_secret. Returns the raw body on success; emits
     * a 401 WP_REST_Response otherwise.
     */
    protected function verifyOrReject(WP_REST_Request $request)
    {
        $header = (string) $request->get_header('X-Pitchbar-Signature');
        if ($header === '') {
            return $this->unauthorized('missing_signature');
        }

        $secret = (string) Plugin::instance()->setting('shopper_signing_secret', '');
        if ($secret === '') {
            return $this->unauthorized('plugin_unconfigured');
        }

        $body = (string) $request->get_body();
        if (! Hmac::verify($header, $secret, $body)) {
            Logger::warn('Plugin REST HMAC mismatch', [
                'route' => $request->get_route(),
            ]);

            return $this->unauthorized('signature_mismatch');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ok(array $data): WP_REST_Response
    {
        return new WP_REST_Response(['data' => $data], 200);
    }

    protected function unauthorized(string $code): WP_REST_Response
    {
        return new WP_REST_Response([
            'error' => [
                'code' => $code,
                'message' => $this->errorMessage($code),
            ],
        ], 401);
    }

    protected function badRequest(string $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response([
            'error' => ['code' => $code, 'message' => $message],
        ], 400);
    }

    private function errorMessage(string $code): string
    {
        switch ($code) {
            case 'missing_signature':
                return 'X-Pitchbar-Signature header missing.';
            case 'plugin_unconfigured':
                return 'Plugin has no API token configured.';
            case 'signature_mismatch':
                return 'HMAC signature did not verify.';
            default:
                return 'Unauthenticated.';
        }
    }
}
