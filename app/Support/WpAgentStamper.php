<?php

namespace App\Support;

use App\Models\Agent;
use Illuminate\Http\Request;

/**
 * Stamps the most-recent WordPress-plugin signal onto the target agent's
 * `wp_integration` JSON column. Drives the "WordPress connected" rows on
 * the /app/integrations admin page so customers can see at a glance
 * which agents have an active plugin pointed at them.
 *
 * Every plugin → server call that carries an `agent_id` should funnel
 * through `touch()` so the stamp stays fresh on heartbeat-style traffic
 * (single-post deltas, full-catalog syncs, coupon refreshes). The
 * handshake is the exception — it doesn't know which agent the
 * operator will attach yet.
 *
 * Tenancy: the WP routes are already gated by the
 * `auth.api_token:wp:integration` middleware, which binds
 * `CurrentWorkspace` to the token's workspace. The agent lookup here is
 * scoped to that workspace; an attacker can't bump another tenant's
 * agent by guessing an id because the lookup will return null.
 */
final class WpAgentStamper
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Merge plugin metadata into the agent's `wp_integration` column and
     * stamp `last_seen_at = now()`. Silent no-op when the agent id
     * doesn't resolve to an agent in the current workspace — keeps the
     * plugin's outbound calls succeeding even when an admin re-pointed
     * the plugin at a stale agent id.
     */
    public function touch(?string $agentId, Request $request): void
    {
        if (! is_string($agentId) || $agentId === '') {
            return;
        }

        $workspace = $this->current->get();
        if ($workspace === null) {
            return;
        }

        $agent = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($agentId)
            ->first();

        if ($agent === null) {
            return;
        }

        $existing = is_array($agent->wp_integration) ? $agent->wp_integration : [];

        $payload = array_merge($existing, [
            'site_url' => $this->stringOrKeep($request->input('site_url'), $existing['site_url'] ?? null),
            'plugin_version' => $this->stringOrKeep($request->input('plugin_version'), $existing['plugin_version'] ?? null),
            'wordpress_version' => $this->stringOrKeep($request->input('wordpress_version'), $existing['wordpress_version'] ?? null),
            'woocommerce_active' => $this->boolOrKeep($request->input('woocommerce_active'), $existing['woocommerce_active'] ?? null),
            'last_seen_at' => now()->toIso8601String(),
        ]);

        $agent->forceFill(['wp_integration' => $payload])->save();
    }

    private function stringOrKeep(mixed $candidate, mixed $fallback): ?string
    {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        return is_string($fallback) ? $fallback : null;
    }

    private function boolOrKeep(mixed $candidate, mixed $fallback): ?bool
    {
        if (is_bool($candidate)) {
            return $candidate;
        }

        if (is_string($candidate) || is_int($candidate)) {
            return filter_var($candidate, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return is_bool($fallback) ? $fallback : null;
    }
}
