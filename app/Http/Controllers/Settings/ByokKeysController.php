<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\ByokResolver;
use App\Support\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-owner-facing BYOK keys form. Renders conditionally on
 * ByokResolver::isUnlockedFor(currentUser, currentWorkspace). When
 * locked, the route 404s — the page itself never reveals that BYOK
 * exists in the install, which keeps the operator's commercial
 * positioning intact.
 */
class ByokKeysController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly ByokResolver $byok,
    ) {}

    public function edit(Request $request): Response
    {
        $this->abortForSuperAdmin($request);
        $workspace = $this->workspace();
        abort_unless($this->byok->isUnlockedFor($request->user(), $workspace), 404);

        $keys = $workspace->byok_keys ?? [];

        return Inertia::render('settings/byok-keys', [
            'has_keys' => [
                'cloudflare' => $this->byok->hasKeysFor($workspace, 'cloudflare'),
                'openai' => $this->byok->hasKeysFor($workspace, 'openai'),
                'openrouter' => $this->byok->hasKeysFor($workspace, 'openrouter'),
                'qdrant' => $this->byok->hasKeysFor($workspace, 'qdrant'),
            ],
            // We never echo the encrypted values back into the form —
            // operator types fresh each rotation. Only emit hints
            // (account_id, vectorize_index, model overrides) that are
            // safe to display.
            'public_fields' => [
                'cloudflare_account_id' => (string) ($keys['cloudflare_account_id'] ?? ''),
                'cloudflare_vectorize_index' => (string) ($keys['cloudflare_vectorize_index'] ?? ''),
                'cloudflare_chat_model' => (string) ($keys['cloudflare_chat_model'] ?? ''),
                'cloudflare_embed_model' => (string) ($keys['cloudflare_embed_model'] ?? ''),
                'openai_chat_model' => (string) ($keys['openai_chat_model'] ?? ''),
                'openai_embed_model' => (string) ($keys['openai_embed_model'] ?? ''),
                'openrouter_chat_model' => (string) ($keys['openrouter_chat_model'] ?? ''),
                'qdrant_url' => (string) ($keys['qdrant_url'] ?? ''),
                'qdrant_collection' => (string) ($keys['qdrant_collection'] ?? ''),
            ],
            'global_byok_required' => $this->byok->globalEnabled(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->abortForSuperAdmin($request);
        $workspace = $this->workspace();
        abort_unless($this->byok->isUnlockedFor($request->user(), $workspace), 404);

        $data = $request->validate([
            'cloudflare_account_id' => ['nullable', 'string', 'max:255'],
            'cloudflare_api_token' => ['nullable', 'string', 'max:500'],
            'cloudflare_vectorize_index' => ['nullable', 'string', 'max:64'],
            'cloudflare_chat_model' => ['nullable', 'string', 'max:128'],
            'cloudflare_embed_model' => ['nullable', 'string', 'max:128'],
            'openai_api_key' => ['nullable', 'string', 'max:500'],
            'openai_chat_model' => ['nullable', 'string', 'max:128'],
            'openai_embed_model' => ['nullable', 'string', 'max:128'],
            'openrouter_api_key' => ['nullable', 'string', 'max:500'],
            'openrouter_chat_model' => ['nullable', 'string', 'max:128'],
            'qdrant_url' => ['nullable', 'url', 'max:500'],
            'qdrant_api_key' => ['nullable', 'string', 'max:500'],
            'qdrant_collection' => ['nullable', 'string', 'max:128'],
        ]);

        $existing = (array) ($workspace->byok_keys ?? []);
        $merged = $existing;
        foreach ($data as $field => $value) {
            // Empty string = leave existing (so partial form submits
            // don't blank out the other provider's keys). Operator
            // clicks the "Clear" button per provider to actively
            // remove credentials.
            if ($value === null || $value === '') {
                continue;
            }
            $merged[$field] = $value;
        }

        $workspace->byok_keys = $merged;
        $workspace->save();

        return back()->with('success', 'API keys saved.');
    }

    public function clear(Request $request, string $provider): RedirectResponse
    {
        $this->abortForSuperAdmin($request);
        $workspace = $this->workspace();
        abort_unless($this->byok->isUnlockedFor($request->user(), $workspace), 404);

        $existing = (array) ($workspace->byok_keys ?? []);
        $prefixes = match ($provider) {
            'cloudflare' => ['cloudflare_'],
            'openai' => ['openai_'],
            'openrouter' => ['openrouter_'],
            'qdrant' => ['qdrant_'],
            default => null,
        };
        if ($prefixes === null) {
            return back()->with('error', 'Unknown provider.');
        }

        $filtered = [];
        foreach ($existing as $field => $value) {
            $shouldClear = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($field, $prefix)) {
                    $shouldClear = true;
                    break;
                }
            }
            if (! $shouldClear) {
                $filtered[$field] = $value;
            }
        }

        $workspace->byok_keys = $filtered;
        $workspace->save();

        return back()->with('success', ucfirst($provider).' keys cleared.');
    }

    private function workspace(): Workspace
    {
        $workspace = $this->current->get();
        abort_unless($workspace !== null, 404);

        return $workspace;
    }

    /**
     * Super-admins manage platform-wide AI credentials via
     * /settings/system — workspace-level BYOK isn't for them. Hard
     * 404 here so a curious operator typing the URL directly still
     * doesn't see the customer-facing form. Nav hiding lives in
     * HandleInertiaRequests::byokFlag (the same isSuperAdmin check
     * gates the sidebar link).
     */
    private function abortForSuperAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user !== null && $user->isSuperAdmin()) {
            abort(404);
        }
    }
}
