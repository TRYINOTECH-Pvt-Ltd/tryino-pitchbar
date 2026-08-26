<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shared session-state helper for OAuth start/callback flows.
 *
 * `start()` stores a CSRF-style nonce + the workspace_id under a per-provider
 * session key, alongside an issued-at timestamp.
 *
 * `consume()` is one-shot: it pulls the entry out of the session, verifies
 * the nonce matches, and rejects if the entry is older than $ttlMinutes
 * (default 10). Returns the workspace_id on success, null on any failure.
 */
class OAuthState
{
    public const DEFAULT_TTL_MINUTES = 10;

    public static function start(Request $request, string $provider, string $workspaceId): string
    {
        $state = Str::random(40);
        $request->session()->put(self::sessionKey($provider), [
            'state' => $state,
            'workspace_id' => $workspaceId,
            'issued_at' => now()->toIso8601String(),
        ]);

        return $state;
    }

    /**
     * @return ?string workspace_id if state is valid; null otherwise.
     */
    public static function consume(
        Request $request,
        string $provider,
        string $presentedState,
        int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
    ): ?string {
        $stash = (array) $request->session()->pull(self::sessionKey($provider), []);
        $expected = $stash['state'] ?? null;
        $workspaceId = $stash['workspace_id'] ?? null;
        $issuedAt = $stash['issued_at'] ?? null;

        if (! is_string($expected) || ! is_string($workspaceId) || $expected === '' || $workspaceId === '') {
            return null;
        }

        if (! hash_equals($expected, $presentedState)) {
            return null;
        }

        if (is_string($issuedAt)) {
            try {
                $expired = now()->diffInMinutes(now()->parse($issuedAt), absolute: true) > $ttlMinutes;
                if ($expired) {
                    return null;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return $workspaceId;
    }

    private static function sessionKey(string $provider): string
    {
        return "{$provider}.oauth_state";
    }
}
