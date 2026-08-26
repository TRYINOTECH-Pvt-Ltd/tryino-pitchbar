<?php

use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('admin can view the api tokens page', function () {
    ['user' => $user] = workspaceMember(['role' => 'admin']);

    $this->actingAs($user)
        ->get('/settings/api-tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/api-tokens')
            ->has('abilities')
            ->where('abilities.0', 'wp:integration'));
});

test('viewer role cannot view the api tokens page', function () {
    ['user' => $user] = workspaceMember(['role' => 'viewer']);

    $this->actingAs($user)
        ->get('/settings/api-tokens')
        ->assertForbidden();
});

test('admin can issue a token and the plaintext is returned exactly once', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $response = $this->actingAs($user)
        ->post('/settings/api-tokens', [
            'name' => 'WordPress: shop.example.com',
            'abilities' => ['wp:integration'],
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('new_token');

    $plaintext = session('new_token');
    expect($plaintext)->toStartWith('pbar_');

    $token = WorkspaceApiToken::query()->where('workspace_id', $workspace->id)->sole();
    expect($token->token_hash)->toBe(hash('sha256', $plaintext));
    expect($token->abilities)->toBe(['wp:integration']);
    expect($token->revoked_at)->toBeNull();
});

test('plaintext is not persisted anywhere', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $this->actingAs($user)->post('/settings/api-tokens', [
        'name' => 'test',
        'abilities' => ['wp:integration'],
    ]);

    $token = WorkspaceApiToken::query()->where('workspace_id', $workspace->id)->sole();
    $plaintext = session('new_token');

    expect($token->getRawOriginal('token_hash'))->not()->toBe($plaintext);
    expect(strlen($token->token_hash))->toBe(64); // sha256 hex
});

// Regression: the `encrypted` cast (2026_05_16) made shopper_signing_secret
// hold a ~250-char envelope, but the column stayed VARCHAR(64), so every
// token creation 500'd on MySQL with "Data too long for column
// 'shopper_signing_secret'". SQLite ignores VARCHAR length, hence it slipped
// through CI. The column is now TEXT.
test('the shopper signing secret is stored encrypted and round-trips without truncation', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $this->actingAs($user)->post('/settings/api-tokens', [
        'name' => 'WordPress: shop.example.com',
        'abilities' => ['wp:integration'],
    ])->assertRedirect()->assertSessionMissing('error');

    $token = WorkspaceApiToken::query()->where('workspace_id', $workspace->id)->sole();

    // The cast decrypts back to the original 48-char plaintext — proof the
    // full encrypted envelope survived the write intact.
    expect($token->shopper_signing_secret)->toBeString();
    expect(strlen($token->shopper_signing_secret))->toBe(48);

    // The value actually at rest is the encrypted envelope, far longer than
    // the old VARCHAR(64) — this is exactly what overflowed on MySQL.
    expect(strlen($token->getRawOriginal('shopper_signing_secret')))->toBeGreaterThan(64);
});

test('encrypted secret columns are TEXT, wide enough for the encrypted envelope', function () {
    // If either column is narrowed back to a length-limited string, MySQL
    // truncates the encrypted envelope and token / webhook creation 500s.
    expect(Schema::getColumnType('workspace_api_tokens', 'shopper_signing_secret'))->toBe('text');
    expect(Schema::getColumnType('webhook_subscriptions', 'secret'))->toBe('text');
});

test('unknown abilities are rejected', function () {
    ['user' => $user] = workspaceMember(['role' => 'admin']);

    $this->actingAs($user)
        ->post('/settings/api-tokens', [
            'name' => 'test',
            'abilities' => ['root:*'],
        ])
        ->assertSessionHasErrors('abilities.0');
});
