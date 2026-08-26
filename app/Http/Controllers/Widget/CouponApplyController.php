<?php

namespace App\Http\Controllers\Widget;

use App\Models\Conversation;
use App\Models\Source;
use App\Models\WorkspaceApiToken;
use App\Scopes\WorkspaceScope;
use App\Services\Widget\WidgetJwt;
use App\Support\HmacSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Visitor-facing endpoint the widget calls after a Copy/Apply click on
 * a `<coupon/>` block. Proxies the apply request to the plugin's
 * `/wp-json/pitchbar/v1/cart/coupon` REST endpoint so the widget never
 * needs cross-origin access to the WP site.
 *
 * Auth: widget JWT (Bearer). The JWT is workspace-scoped via its
 * conversation, so the apply call can only land on the WP site
 * registered as a source under that agent.
 */
class CouponApplyController
{
    public function __construct(private readonly WidgetJwt $jwt) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token)) {
            return new JsonResponse(['error' => ['code' => 'missing_token']], 401);
        }
        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable) {
            return new JsonResponse(['error' => ['code' => 'invalid_token']], 401);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
        ]);

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->find($conversationId);
        if ($conversation === null) {
            return new JsonResponse(['error' => ['code' => 'conversation_not_found']], 404);
        }

        $source = Source::query()
            ->withoutGlobalScopes()
            ->where('agent_id', $conversation->agent_id)
            ->where('type', 'woocommerce_products')
            ->orderByDesc('last_synced_at')
            ->first();
        if ($source === null) {
            return new JsonResponse([
                'error' => ['code' => 'no_woocommerce_source', 'message' => 'No WooCommerce store attached to this agent.'],
            ], 404);
        }

        $baseUrl = rtrim((string) ($source->config['site_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return new JsonResponse([
                'error' => ['code' => 'no_site_url'],
            ], 404);
        }

        $secret = $this->resolveSigningSecret((string) $conversation->agent->workspace_id);
        if ($secret === null) {
            return new JsonResponse([
                'error' => ['code' => 'no_signing_secret'],
            ], 500);
        }

        $body = (string) json_encode([
            'code' => $data['code'],
            'conversation_id' => $conversationId,
        ], JSON_THROW_ON_ERROR);
        $signature = HmacSignature::sign($secret, $body);

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Pitchbar-Signature' => $signature,
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($baseUrl.'/wp-json/pitchbar/v1/cart/coupon');
        } catch (\Throwable $e) {
            Log::warning('CouponApply transport error', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => ['code' => 'transport_error']], 502);
        }

        $payload = is_array($response->json()) ? (array) $response->json() : [];

        if (! $response->successful()) {
            return new JsonResponse([
                'error' => $payload['error'] ?? ['code' => 'http_'.$response->status()],
            ], $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502);
        }

        return new JsonResponse(['data' => $payload['data'] ?? $payload]);
    }

    private function resolveSigningSecret(string $workspaceId): ?string
    {
        $token = WorkspaceApiToken::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereNull('revoked_at')
            ->whereNotNull('shopper_signing_secret')
            ->orderByDesc('last_used_at')
            ->first(['shopper_signing_secret']);

        return $token === null ? null : (string) $token->shopper_signing_secret;
    }
}
