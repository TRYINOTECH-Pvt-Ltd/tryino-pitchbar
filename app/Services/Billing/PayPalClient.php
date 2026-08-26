<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the PayPal REST API.
 *
 * Mirrors the role `Stripe\StripeClient` plays for the Stripe gateway:
 * holds credentials, knows the right base URL, exposes the small set of
 * verbs the rest of the billing layer actually needs (catalog products,
 * billing plans, billing subscriptions, webhook signature verification).
 *
 * OAuth2 client-credentials access tokens are fetched on demand and
 * cached for ~9 minutes (PayPal issues 32400-second tokens; we hold
 * them well below the expiry to avoid mid-request invalidation).
 */
class PayPalClient
{
    public const MODE_LIVE = 'live';

    public const MODE_SANDBOX = 'sandbox';

    private const TOKEN_CACHE_TTL_SECONDS = 540;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mode = self::MODE_SANDBOX,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.paypal.client_id', ''),
            (string) config('services.paypal.client_secret', ''),
            (string) config('services.paypal.mode', self::MODE_SANDBOX),
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function baseUrl(): string
    {
        return $this->mode === self::MODE_LIVE
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * @return array<string, mixed>
     */
    public function createProduct(string $name, ?string $description = null): array
    {
        $payload = [
            'name' => $name,
            'type' => 'SERVICE',
            'category' => 'SOFTWARE',
        ];

        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        return $this->json($this->authed()->post('/v1/catalogs/products', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPlan(array $payload): array
    {
        return $this->json($this->authed()->post('/v1/billing/plans', $payload));
    }

    public function deactivatePlan(string $planId): void
    {
        $this->authed()->post("/v1/billing/plans/{$planId}/deactivate");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createSubscription(array $payload): array
    {
        return $this->json($this->authed()->post('/v1/billing/subscriptions', $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->json($this->authed()->get("/v1/billing/subscriptions/{$subscriptionId}"));
    }

    public function cancelSubscription(string $subscriptionId, string $reason = 'Customer request'): void
    {
        $this->authed()->post(
            "/v1/billing/subscriptions/{$subscriptionId}/cancel",
            ['reason' => $reason],
        );
    }

    /**
     * Verify a webhook signature against PayPal's signature endpoint.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public function verifyWebhook(array $headers, array $body, string $webhookId): bool
    {
        $payload = [
            'auth_algo' => $this->header($headers, 'paypal-auth-algo'),
            'cert_url' => $this->header($headers, 'paypal-cert-url'),
            'transmission_id' => $this->header($headers, 'paypal-transmission-id'),
            'transmission_sig' => $this->header($headers, 'paypal-transmission-sig'),
            'transmission_time' => $this->header($headers, 'paypal-transmission-time'),
            'webhook_id' => $webhookId,
            'webhook_event' => $body,
        ];

        try {
            $response = $this->authed()->post('/v1/notifications/verify-webhook-signature', $payload);

            return ($response->json('verification_status') ?? '') === 'SUCCESS';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function header(array $headers, string $key): string
    {
        $lower = array_change_key_case($headers, CASE_LOWER);

        return (string) ($lower[strtolower($key)] ?? '');
    }

    /**
     * Pre-authenticated Http client with the OAuth bearer applied.
     */
    private function authed(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withToken($this->accessToken())
            ->throw();
    }

    /**
     * Fetch & cache a client-credentials access token.
     */
    private function accessToken(): string
    {
        $cacheKey = 'paypal:token:'.$this->mode.':'.md5($this->clientId);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->acceptJson()
            ->throw()
            ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = (string) ($response->json('access_token') ?? '');

        if ($token === '') {
            throw new \RuntimeException('PayPal returned an empty access token.');
        }

        Cache::put($cacheKey, $token, self::TOKEN_CACHE_TTL_SECONDS);

        return $token;
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
