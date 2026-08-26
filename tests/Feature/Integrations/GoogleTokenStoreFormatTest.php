<?php

use App\Models\IntegrationConnection;
use App\Models\Workspace;
use App\Services\Integrations\Google\GoogleException;
use App\Services\Integrations\Google\GoogleTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Card #504. GoogleTokenStore writes expires_at as ISO8601, but the
 * sheet-sync job used to write a unix EPOCH into the same credentials row
 * — and Carbon cannot parse a numeric string as a date, so one sheet sync
 * crashed every Google Doc ingest for the workspace
 * (DateMalformedStringException, all doc sources flipped to failed, and
 * failed sources are excluded from the hourly autosync so nothing
 * self-healed). The store now reads any of the three shapes.
 */
function googleConnection(mixed $expiresAt): IntegrationConnection
{
    $workspace = Workspace::factory()->create();

    return IntegrationConnection::create([
        'workspace_id' => $workspace->id,
        'kind' => 'google',
        'credentials_encrypted' => [
            'access_token' => 'ya29.live-token',
            'refresh_token' => '1//refresh',
            'expires_at' => $expiresAt,
        ],
        'status' => 'active',
    ]);
}

test('a still-valid epoch STRING expires_at returns the stored token instead of throwing', function () {
    // The exact poisoned shape from the live incident: (string) of
    // time()+3600 written by the old sheet-sync refresh.
    $connection = googleConnection((string) now()->addHour()->getTimestamp());

    expect(app(GoogleTokenStore::class)->activeAccessToken($connection))
        ->toBe('ya29.live-token');
});

test('a still-valid epoch INT expires_at returns the stored token', function () {
    $connection = googleConnection(now()->addHour()->getTimestamp());

    expect(app(GoogleTokenStore::class)->activeAccessToken($connection))
        ->toBe('ya29.live-token');
});

test('a still-valid ISO expires_at returns the stored token', function () {
    $connection = googleConnection(now()->addHour()->toIso8601String());

    expect(app(GoogleTokenStore::class)->activeAccessToken($connection))
        ->toBe('ya29.live-token');
});

test('an EXPIRED epoch string goes down the refresh path, not the crash path', function () {
    $connection = googleConnection((string) now()->subMinute()->getTimestamp());

    // No HTTP fake for the refresh call — reaching Google's refresh error
    // (any GoogleException) proves the expiry comparison worked instead of
    // throwing DateMalformedStringException before it.
    expect(fn () => app(GoogleTokenStore::class)->activeAccessToken($connection))
        ->toThrow(GoogleException::class);
});
