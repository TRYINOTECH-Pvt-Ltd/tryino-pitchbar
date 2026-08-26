<?php

namespace App\Services\Integrations\Google;

use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;

/**
 * Wraps an `IntegrationConnection` of kind=google. Returns a fresh access
 * token, transparently refreshing it via the refresh_token when needed.
 */
class GoogleTokenStore
{
    public function __construct(private readonly GoogleClient $client) {}

    public function activeAccessToken(IntegrationConnection $connection): string
    {
        $creds = (array) ($connection->credentials_encrypted ?? []);
        $accessToken = (string) ($creds['access_token'] ?? '');
        $refreshToken = (string) ($creds['refresh_token'] ?? '');
        $expiresAt = $creds['expires_at'] ?? null;

        // Card #504: tolerate an epoch here instead of throwing. This store
        // writes ISO, but the sheet-sync job used to write a unix epoch
        // into the SAME credentials row, and Carbon cannot parse a numeric
        // STRING as a date — one poisoned value then crashed every Google
        // Doc ingest for the workspace until the row was hand-edited. A
        // numeric expires_at is read as a timestamp; the next refresh
        // rewrites it as ISO and the row self-heals.
        if (is_numeric($expiresAt)) {
            $expiresAt = CarbonImmutable::createFromTimestamp((int) $expiresAt);
        }

        $isExpired = $expiresAt === null
            || now()->greaterThanOrEqualTo($expiresAt);

        if (! $isExpired && $accessToken !== '') {
            return $accessToken;
        }

        if ($refreshToken === '') {
            throw new GoogleException('Google access token expired and no refresh token is on file. Reconnect Google.');
        }

        try {
            $fresh = $this->client->refresh($refreshToken);
        } catch (GoogleException $e) {
            // A dead refresh token (user revoked access, or Google expired
            // it) will fail every future call too — flip the connection out
            // of 'active' so the Integrations page shows the reconnect
            // state and new Google sources are blocked with a clear message
            // instead of being created doomed. Transient refresh errors
            // (network, 5xx) don't carry these markers and stay 'active'.
            $message = mb_strtolower($e->getMessage());
            if (str_contains($message, 'expired or revoked')
                || str_contains($message, 'invalid_grant')
            ) {
                $connection->update(['status' => 'expired']);

                throw new GoogleException(
                    'Google connection expired or was revoked — reconnect Google under Integrations. ('.$e->getMessage().')',
                );
            }

            throw $e;
        }

        $connection->update([
            'credentials_encrypted' => array_merge($creds, [
                'access_token' => $fresh['access_token'],
                'expires_at' => now()->addSeconds($fresh['expires_in'])->toIso8601String(),
            ]),
        ]);

        return $fresh['access_token'];
    }
}
