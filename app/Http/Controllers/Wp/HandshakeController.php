<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\WorkspaceApiToken;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First call the WordPress plugin makes after the operator pastes the
 * Pitchbar URL + API token. Confirms the token resolves to a real
 * workspace and returns the list of agents the operator can attach
 * to. Plugin caches the workspace_id + selected agent_id locally.
 *
 * Auth: bearer token via `auth.api_token:wp:integration` middleware.
 * The middleware has already hydrated CurrentWorkspace by the time
 * the handler runs — no manual scope juggling needed here.
 */
class HandshakeController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_url' => ['required', 'string', 'url', 'max:500'],
            'plugin_version' => ['required', 'string', 'max:32'],
            'woocommerce_active' => ['nullable', 'boolean'],
            'wordpress_version' => ['nullable', 'string', 'max:32'],
        ]);

        $workspace = $this->current->get();
        abort_unless($workspace !== null, 401);

        /** @var WorkspaceApiToken|null $token */
        $token = $request->attributes->get('api_token');

        $agents = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'name', 'site_type', 'language_default', 'is_published'])
            ->map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'site_type' => $a->site_type,
                'language_default' => $a->language_default,
                'is_published' => (bool) $a->is_published,
            ]);

        return response()->json([
            'data' => [
                'workspace' => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ],
                'agents' => $agents,
                'token' => [
                    'id' => $token?->id,
                    'name' => $token?->name,
                    'abilities' => $token?->abilities ?? [],
                    // Per-token signing secret the plugin uses to sign
                    // short-lived shopper tokens for logged-in WC
                    // customers. Plaintext is safe to surface — the
                    // handshake is already gated by the bearer.
                    'shopper_signing_secret' => $token?->shopper_signing_secret,
                ],
                'recommended_site_type' => $data['woocommerce_active'] ?? false ? 'ecommerce' : null,
                'echo' => [
                    'site_url' => $data['site_url'],
                    'plugin_version' => $data['plugin_version'],
                ],
            ],
        ]);
    }
}
