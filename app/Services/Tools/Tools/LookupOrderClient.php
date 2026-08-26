<?php

namespace App\Services\Tools\Tools;

use App\Support\HmacSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound HTTP client for the WordPress plugin's `/orders/lookup`
 * REST endpoint. Signs the request body with HMAC-SHA256 using the
 * workspace's API token plaintext as the shared secret (mirror of
 * the inbound `VerifyHmacSignature` middleware).
 *
 * 5s hard timeout — beyond that the LLM has been waiting for a tool
 * result long enough that we return an explicit timeout error so it
 * stops looping.
 */
class LookupOrderClient
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool, data:array<string, mixed>, error:?string}
     */
    public function call(string $baseUrl, string $apiTokenPlaintext, array $payload): array
    {
        $url = rtrim($baseUrl, '/').'/wp-json/pitchbar/v1/orders/lookup';
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = HmacSignature::sign($apiTokenPlaintext, $body);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Pitchbar-Signature' => $signature,
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('LookupOrderClient transport error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'data' => [], 'error' => 'transport_error'];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'data' => is_array($response->json()) ? (array) $response->json() : [],
                'error' => 'http_'.$response->status(),
            ];
        }

        $json = $response->json();
        $data = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : (is_array($json) ? $json : []);

        return ['ok' => true, 'data' => $data, 'error' => null];
    }
}
