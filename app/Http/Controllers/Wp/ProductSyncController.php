<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Integrations\WordPress\WooProductIngestService;
use App\Support\CurrentWorkspace;
use App\Support\WpAgentStamper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk WooCommerce product ingest. Plugin pages `wc_get_products`
 * in batches of 50 and POSTs each page here. Body shape mirrors
 * `PostSyncController` but with `products[]` instead of `posts[]`.
 *
 * Auth: `auth.api_token:wp:integration`.
 * Integrity: `hmac.signature` verifies the request body.
 */
class ProductSyncController extends Controller
{
    public const MAX_BATCH_SIZE = 50;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly WooProductIngestService $service,
        private readonly WpAgentStamper $stamper,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'uuid'],
            'site_url' => ['required', 'string', 'url', 'max:500'],
            'plugin_version' => ['required', 'string', 'max:32'],
            'products' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'products.*.wp_id' => ['required'],
            'products.*.sku' => ['nullable', 'string', 'max:160'],
            'products.*.name' => ['required', 'string', 'max:500'],
            'products.*.permalink' => ['required', 'string', 'url', 'max:1000'],
            'products.*.image_url' => ['nullable', 'string', 'url', 'max:1000'],
            'products.*.short_description' => ['nullable', 'string', 'max:5000'],
            // Hard cap at 512KB per product description.
            'products.*.description' => ['nullable', 'string', 'max:524288'],
            'products.*.price' => ['nullable', 'string', 'max:32'],
            'products.*.regular_price' => ['nullable', 'string', 'max:32'],
            'products.*.sale_price' => ['nullable', 'string', 'max:32'],
            'products.*.currency' => ['nullable', 'string', 'max:8'],
            'products.*.stock_status' => ['nullable', 'string', 'max:32'],
            'products.*.on_sale' => ['nullable', 'boolean'],
            'products.*.content_hash' => ['required', 'string', 'size:64'],
            'products.*.modified_at' => ['nullable', 'string', 'max:64'],
            'products.*.categories' => ['nullable', 'array'],
            'products.*.categories.*' => ['string', 'max:160'],
            'products.*.attributes' => ['nullable', 'array'],
            'products.*.attributes.*' => ['string', 'max:300'],
        ]);

        $workspace = $this->current->get();
        abort_unless($workspace !== null, 401);

        $agent = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $data['agent_id'])
            ->first();

        if ($agent === null) {
            return new JsonResponse([
                'error' => ['code' => 'agent_not_found', 'message' => 'Agent does not belong to this workspace.'],
            ], 404);
        }

        // Stamp the most-recent plugin signal so /app/integrations
        // reflects "WordPress + WooCommerce connected" on this agent.
        $this->stamper->touch($data['agent_id'], $request);

        $result = $this->service->upsertBatch(
            $agent,
            $data['site_url'],
            $data['plugin_version'],
            $data['products'],
        );

        return new JsonResponse(['data' => $result], 200);
    }
}
