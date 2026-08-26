<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Razorpay REST API.
 *
 * Same role as {@see PayPalClient} for PayPal: hold credentials, expose
 * the small set of verbs the rest of the billing layer needs (plans,
 * subscriptions, signature verification).
 *
 * Razorpay uses HTTP Basic auth with `key_id : key_secret` directly —
 * no OAuth dance, no token caching. Webhook signatures are HMAC-SHA256
 * of the raw body keyed by a shared secret configured in the dashboard.
 */
class RazorpayClient
{
    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.razorpay.key_id', ''),
            (string) config('services.razorpay.key_secret', ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    public function publicKey(): string
    {
        return $this->keyId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPlan(array $payload): array
    {
        return $this->json($this->authed()->post('/v1/plans', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createSubscription(array $payload): array
    {
        return $this->json($this->authed()->post('/v1/subscriptions', $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->json($this->authed()->get("/v1/subscriptions/{$subscriptionId}"));
    }

    public function cancelSubscription(string $subscriptionId, bool $atCycleEnd = false): void
    {
        $this->authed()->post(
            "/v1/subscriptions/{$subscriptionId}/cancel",
            ['cancel_at_cycle_end' => $atCycleEnd ? 1 : 0],
        );
    }

    /**
     * Verify a webhook payload's HMAC-SHA256 signature against the shared
     * secret. Razorpay sends the signature in `X-Razorpay-Signature`.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature, string $webhookSecret): bool
    {
        if ($signature === '' || $webhookSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signature);
    }

    private function authed(): PendingRequest
    {
        return Http::baseUrl('https://api.razorpay.com')
            ->withBasicAuth($this->keyId, $this->keySecret)
            ->acceptJson()
            ->asJson()
            ->throw();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return $body;
    }
}
