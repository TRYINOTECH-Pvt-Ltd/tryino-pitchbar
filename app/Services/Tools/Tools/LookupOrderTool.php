<?php

namespace App\Services\Tools\Tools;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Source;
use App\Models\WorkspaceApiToken;
use App\Scopes\WorkspaceScope;
use App\Services\Tools\Contracts\HasIntentSignals;
use App\Services\Tools\Contracts\Tool;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a logged-in WooCommerce shopper's recent orders by calling
 * back into the WordPress plugin's `/wp-json/pitchbar/v1/orders/lookup`
 * REST endpoint.
 *
 * Gated by the `order_status` capability — present on the ecommerce
 * preset by default. The tool refuses early when:
 *   - the conversation has no `shopper.wp_user_id` claim in its JWT
 *     (visitor is anonymous; ask them to sign in instead)
 *   - the agent has no `woocommerce_products` Source attached (no
 *     callback target — would just timeout)
 *
 * Latency budget: 5s hard timeout in `LookupOrderClient`. Runs inside
 * the post-first-token `runToolLoop`, never on the pre-stream path.
 */
class LookupOrderTool implements HasIntentSignals, Tool
{
    public function __construct(private readonly LookupOrderClient $client) {}

    public function name(): string
    {
        return 'lookup_order';
    }

    public function description(): string
    {
        return "Fetch the visitor's recent orders, including status, items, and tracking. Call this only when the visitor asks about an order they placed, shipping status, refunds, or returns. The visitor must be signed in for this to work.";
    }

    public function capability(): string
    {
        return 'order_status';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many recent orders to fetch (1-10). Defaults to 5.',
                    'minimum' => 1,
                    'maximum' => 10,
                ],
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Optional order number filter, when the visitor mentions a specific order.',
                ],
            ],
        ];
    }

    public function execute(array $args, Agent $agent, array $context = []): array
    {
        /** @var Conversation|null $conversation */
        $conversation = $context['conversation'] ?? null;

        $wpUserId = $this->extractWpUserId($conversation);
        if ($wpUserId === null) {
            return [
                'result' => [
                    'error' => 'not_signed_in',
                    'message' => 'The visitor is not signed in to the WordPress site. Ask them to sign in and try again.',
                ],
            ];
        }

        $source = $this->resolveSource($agent);
        if ($source === null) {
            return [
                'result' => [
                    'error' => 'no_wordpress_source',
                    'message' => 'No WordPress store is attached to this agent.',
                ],
            ];
        }

        $signingSecret = $this->resolveSigningSecret($agent->workspace_id);
        if ($signingSecret === null) {
            Log::warning('LookupOrderTool: no active API token has a shopper signing secret', [
                'agent_id' => $agent->id,
            ]);

            return [
                'result' => [
                    'error' => 'no_signing_secret',
                    'message' => 'The agent has no active API token configured.',
                ],
            ];
        }

        $baseUrl = (string) ($source->config['site_url'] ?? '');
        if ($baseUrl === '') {
            return [
                'result' => [
                    'error' => 'no_site_url',
                    'message' => 'The agent has no WordPress site URL configured.',
                ],
            ];
        }

        $limit = isset($args['limit']) ? max(1, min(10, (int) $args['limit'])) : 5;
        $orderNumber = isset($args['order_number']) ? (string) $args['order_number'] : null;

        $payload = [
            'wp_user_id' => $wpUserId,
            'limit' => $limit,
        ];
        if ($orderNumber !== null && $orderNumber !== '') {
            $payload['order_number'] = $orderNumber;
        }

        $response = $this->client->call($baseUrl, $signingSecret, $payload);

        if (! $response['ok']) {
            return [
                'result' => [
                    'error' => $response['error'] ?? 'lookup_failed',
                    'message' => 'Could not fetch orders right now.',
                ],
            ];
        }

        return [
            'result' => [
                'orders' => $response['data']['orders'] ?? [],
                'count' => (int) ($response['data']['count'] ?? count($response['data']['orders'] ?? [])),
            ],
        ];
    }

    private function extractWpUserId(?Conversation $conversation): ?string
    {
        if ($conversation === null) {
            return null;
        }
        $attribution = (array) ($conversation->attribution ?? []);
        $shopper = (array) ($attribution['shopper'] ?? []);
        $wpUserId = (string) ($shopper['wp_user_id'] ?? '');

        return $wpUserId !== '' ? $wpUserId : null;
    }

    private function resolveSource(Agent $agent): ?Source
    {
        return Source::query()
            ->withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('type', 'woocommerce_products')
            ->orderByDesc('last_synced_at')
            ->first();
    }

    private function resolveSigningSecret(string $workspaceId): ?string
    {
        $token = WorkspaceApiToken::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereNull('revoked_at')
            ->whereNotNull('shopper_signing_secret')
            ->orderByDesc('last_used_at')
            ->first(['id', 'shopper_signing_secret']);

        return $token === null ? null : (string) $token->shopper_signing_secret;
    }

    /** @return list<string> */
    public function intentKeywords(): array
    {
        return [
            'my order',
            'order status',
            'order number',
            'track my',
            'tracking number',
            'where is my order',
            'where is my package',
            'has my order shipped',
            'refund',
            'return my',
        ];
    }

    /** @return list<string> */
    public function intentExemplars(): array
    {
        return [
            'where is my order',
            'has my package shipped yet',
            'I want a refund on order 1234',
            'what is the status of my recent purchase',
        ];
    }
}
