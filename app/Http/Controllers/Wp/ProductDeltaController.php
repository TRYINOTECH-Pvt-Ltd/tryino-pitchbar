<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Integrations\WordPress\WooProductIngestService;
use App\Services\Integrations\WordPress\WooProductPayload;
use App\Support\CurrentWorkspace;
use App\Support\WpAgentStamper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single WooCommerce product delta from the WordPress plugin's
 * `woocommerce_update_product` / `woocommerce_delete_product` listeners.
 *
 * Delete is a silent no-op when the product is unknown.
 */
class ProductDeltaController extends Controller
{
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
            'action' => ['required', 'string', 'in:upsert,delete'],
            'product' => ['required', 'array'],
            'product.wp_id' => ['required'],
            'product.sku' => ['nullable', 'string', 'max:160'],
            'product.name' => ['required_if:action,upsert', 'nullable', 'string', 'max:500'],
            'product.permalink' => ['required_if:action,upsert', 'nullable', 'string', 'url', 'max:1000'],
            'product.image_url' => ['nullable', 'string', 'url', 'max:1000'],
            'product.short_description' => ['nullable', 'string', 'max:5000'],
            'product.description' => ['nullable', 'string'],
            'product.price' => ['nullable', 'string', 'max:32'],
            'product.regular_price' => ['nullable', 'string', 'max:32'],
            'product.sale_price' => ['nullable', 'string', 'max:32'],
            'product.currency' => ['nullable', 'string', 'max:8'],
            'product.stock_status' => ['nullable', 'string', 'max:32'],
            'product.on_sale' => ['nullable', 'boolean'],
            'product.content_hash' => ['required_if:action,upsert', 'nullable', 'string', 'size:64'],
            'product.modified_at' => ['nullable', 'string', 'max:64'],
            'product.categories' => ['nullable', 'array'],
            'product.categories.*' => ['string', 'max:160'],
            'product.attributes' => ['nullable', 'array'],
            'product.attributes.*' => ['string', 'max:300'],
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
        // shows "WooCommerce connected" within seconds of a product
        // save in WP admin.
        $this->stamper->touch($data['agent_id'], $request);

        $externalId = 'wc:'.(string) $data['product']['wp_id'];

        if ($data['action'] === 'delete') {
            $deleted = $this->service->delete($agent, $externalId);

            return new JsonResponse(['data' => ['action' => 'delete', 'deleted' => $deleted]], 200);
        }

        $source = $this->service->resolveSource($agent, $data['site_url'], $data['plugin_version']);
        $payload = WooProductPayload::fromArray($data['product']);
        $result = $this->service->upsertOne($agent, $source, $payload);

        return new JsonResponse(['data' => ['action' => 'upsert', 'result' => $result]], 200);
    }
}
