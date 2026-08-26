<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\Workspace;

/**
 * BYOK access matrix.
 *
 *   global ON | user override   → unlocked?
 *   ----------|-----------------|----------
 *   true      | null            | yes — required (visitor sees error when keys missing)
 *   true      | true            | yes
 *   true      | false           | NO (explicit deny wins)
 *   false     | null            | no
 *   false     | true            | yes — granted to this user only
 *   false     | false           | no
 *
 * When BYOK is unlocked AND the workspace has keys, LLM/Vector
 * resolvers prefer the workspace credentials. When unlocked but
 * keys are missing AND global is ON → throw MissingByokKeyException
 * so the visitor sees "this workspace is not configured" instead of
 * a stack trace. When global is OFF and the user is force-on but
 * keys are missing → fall through to platform keys.
 */
final class ByokResolver
{
    /**
     * Whether BYOK is allowed for this user × workspace pair.
     */
    public function isUnlockedFor(?User $user, ?Workspace $workspace): bool
    {
        if ($workspace === null) {
            return false;
        }

        // Explicit per-user deny always wins.
        if ($user !== null && $user->byok_enabled === false) {
            return false;
        }

        // Explicit per-user allow always wins (regardless of global).
        if ($user !== null && $user->byok_enabled === true) {
            return true;
        }

        // No per-user override → fall back to global flag.
        return $this->globalEnabled();
    }

    public function globalEnabled(): bool
    {
        return (bool) AppSetting::singleton()->byok_enabled_globally;
    }

    /**
     * Returns the decrypted keys map for the workspace, or null if
     * none have been configured yet. The keys live encrypted via
     * the Workspace model's `encrypted:array` cast.
     *
     * @return ?array<string, string>
     */
    public function keysFor(Workspace $workspace): ?array
    {
        $keys = $workspace->byok_keys;
        if (! is_array($keys) || $keys === []) {
            return null;
        }

        return $keys;
    }

    /**
     * Whether the workspace has the credentials a particular provider
     * needs. Used by /settings/byok-keys's Test button to render a
     * per-provider status pill.
     */
    public function hasKeysFor(Workspace $workspace, string $provider): bool
    {
        $keys = $this->keysFor($workspace) ?? [];

        return match ($provider) {
            'cloudflare' => ! empty($keys['cloudflare_account_id']) && ! empty($keys['cloudflare_api_token']),
            'openai' => ! empty($keys['openai_api_key']),
            'openrouter' => ! empty($keys['openrouter_api_key']),
            'qdrant' => ! empty($keys['qdrant_url']) && ! empty($keys['qdrant_api_key']),
            default => false,
        };
    }
}
