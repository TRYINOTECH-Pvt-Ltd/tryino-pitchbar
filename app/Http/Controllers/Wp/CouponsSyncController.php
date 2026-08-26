<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Source;
use App\Support\CurrentWorkspace;
use App\Support\WpAgentStamper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refresh the agent's cached WooCommerce coupon list. Called by the
 * plugin's CouponSyncer after every product sync, so the prompt
 * fragment in PromptBuilder always reflects the store's currently
 * active promotions without a per-turn round-trip.
 *
 * Coupons live on the matching `woocommerce_products` source's
 * `config['coupons']` array. Re-running with an empty list clears
 * the cache (e.g. when all coupons expired between syncs).
 */
class CouponsSyncController extends Controller
{
    public const MAX_COUPONS = 50;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly WpAgentStamper $stamper,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'uuid'],
            'site_url' => ['required', 'string', 'url', 'max:500'],
            'plugin_version' => ['required', 'string', 'max:32'],
            'coupons' => ['nullable', 'array', 'max:'.self::MAX_COUPONS],
            'coupons.*.code' => ['required', 'string', 'max:120'],
            'coupons.*.label' => ['required', 'string', 'max:240'],
            'coupons.*.discount' => ['nullable', 'string', 'max:64'],
            'coupons.*.expires_at' => ['nullable', 'string', 'max:64'],
            'coupons.*.url' => ['nullable', 'string', 'url', 'max:1000'],
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
        // sees this agent as actively connected even when coupon
        // syncs are the only traffic (slow-changing catalogs).
        $this->stamper->touch($data['agent_id'], $request);

        $host = $this->canonicalHost($data['site_url']);
        $source = Source::query()
            ->withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('type', 'woocommerce_products')
            ->get()
            ->first(function (Source $candidate) use ($host) {
                $existingHost = $this->canonicalHost((string) ($candidate->config['site_url'] ?? ''));

                return $existingHost === $host;
            });

        if ($source === null) {
            return new JsonResponse([
                'error' => ['code' => 'source_not_found', 'message' => 'No WooCommerce source for this agent + site.'],
            ], 404);
        }

        $config = is_array($source->config) ? $source->config : [];
        $config['coupons'] = $data['coupons'] ?? [];
        $config['coupons_synced_at'] = now()->toIso8601String();
        $source->forceFill(['config' => $config])->save();

        return new JsonResponse([
            'data' => ['count' => count($config['coupons'])],
        ]);
    }

    private function canonicalHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return strtolower($url);
        }

        return strtolower($host);
    }
}
