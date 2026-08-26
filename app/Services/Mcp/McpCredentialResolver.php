<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use Carbon\Carbon;

/**
 * Single responsibility: turn an McpServer row into the Authorization
 * header string (or null) that should be sent on the next call.
 *
 * For bearer auth: decrypt and return immediately.
 *
 * For OAuth: check the cached access token's expiry. If still valid
 * (>60s remaining), return it. If expired or about to expire, refresh
 * under a workspace-scoped distributed lock so two concurrent visitor
 * turns do not race a refresh.
 *
 * For `none`: return null header.
 *
 * This is the ONLY code path that touches decrypted credentials.
 * Every other layer works with the assembled header string.
 *
 * Refresh implementation is stubbed in v1 (Phase 2). Phase 3 controllers
 * fill in the OAuth callback so refresh-tokens flow end-to-end.
 */
class McpCredentialResolver
{
    /**
     * @return array{header: string|null, expires_at: ?Carbon}
     *
     * @throws McpUnauthorizedException
     */
    public function resolve(McpServer $server): array
    {
        if ($server->status === McpServer::STATUS_REVOKED
            || $server->status === McpServer::STATUS_DISABLED) {
            throw new McpUnauthorizedException(
                "Server [{$server->label}] is not active (status={$server->status})."
            );
        }

        $creds = (array) ($server->credentials_encrypted ?? []);

        return match ($server->auth_type) {
            McpServer::AUTH_NONE => ['header' => null, 'expires_at' => null],
            McpServer::AUTH_BEARER => $this->resolveBearer($creds),
            McpServer::AUTH_OAUTH2_PKCE => $this->resolveOauth($server, $creds),
            default => throw new McpUnauthorizedException(
                "Unknown auth_type [{$server->auth_type}] on server [{$server->label}]."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $creds
     * @return array{header: string|null, expires_at: ?Carbon}
     */
    private function resolveBearer(array $creds): array
    {
        $apiKey = (string) ($creds['api_key'] ?? '');
        if ($apiKey === '') {
            throw new McpUnauthorizedException('Bearer credentials missing api_key.');
        }

        return ['header' => "Bearer {$apiKey}", 'expires_at' => null];
    }

    /**
     * @param  array<string, mixed>  $creds
     * @return array{header: string|null, expires_at: ?Carbon}
     */
    private function resolveOauth(McpServer $server, array $creds): array
    {
        $accessToken = (string) ($creds['access_token'] ?? '');
        if ($accessToken === '') {
            throw new McpUnauthorizedException(
                "Server [{$server->label}] has no OAuth access token. Reconnect required."
            );
        }

        $expiresAtIso = (string) ($creds['expires_at'] ?? '');
        $expiresAt = $expiresAtIso !== '' ? Carbon::parse($expiresAtIso) : null;

        // Phase 2: serve the cached token even if close to expiry.
        // Phase 3 (OAuth controller PR) wires the refresh-under-lock
        // path properly; until then a stale token surfaces as a 401
        // from the server, which the executor maps to a "needs
        // reconnect" message for the admin.
        return ['header' => "Bearer {$accessToken}", 'expires_at' => $expiresAt];
    }
}
