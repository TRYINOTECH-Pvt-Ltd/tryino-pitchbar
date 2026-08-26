<?php

namespace App\Services\Webhooks;

use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SignedDispatcher
{
    public function __construct(private readonly Guzzle $http = new Guzzle(['timeout' => 5])) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $url, string $secret, array $payload): bool
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $sig = hash_hmac('sha256', "{$ts}.{$body}", $secret);
        $deliveryId = (string) Str::uuid();
        $eventName = is_string($payload['event'] ?? null) ? $payload['event'] : 'unknown';

        try {
            $response = $this->http->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Pitchbar-Signature' => "t={$ts},v1={$sig}",
                    'X-Pitchbar-Webhook-Id' => $deliveryId,
                    'X-Pitchbar-Event' => $eventName,
                ],
                'body' => $body,
                'http_errors' => false,
            ]);

            return $response->getStatusCode() < 400;
        } catch (\Throwable $e) {
            Log::warning('Webhook dispatch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
