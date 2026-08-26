<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ByokResolver;

function setGlobalByok(bool $enabled): void
{
    $row = AppSetting::singleton();
    $row->byok_enabled_globally = $enabled;
    $row->save();
}

test('matrix: global ON + user inherits → unlocked', function () {
    setGlobalByok(true);
    $user = User::factory()->create(['byok_enabled' => null]);
    $workspace = Workspace::factory()->create();

    expect((new ByokResolver)->isUnlockedFor($user, $workspace))->toBeTrue();
});

test('matrix: global ON + user force-allows → unlocked', function () {
    setGlobalByok(true);
    $user = User::factory()->create(['byok_enabled' => true]);
    $workspace = Workspace::factory()->create();

    expect((new ByokResolver)->isUnlockedFor($user, $workspace))->toBeTrue();
});

test('matrix: global ON + user explicit DENY → locked (explicit deny wins)', function () {
    setGlobalByok(true);
    $user = User::factory()->create(['byok_enabled' => false]);
    $workspace = Workspace::factory()->create();

    expect((new ByokResolver)->isUnlockedFor($user, $workspace))->toBeFalse();
});

test('matrix: global OFF + user inherits → locked', function () {
    setGlobalByok(false);
    $user = User::factory()->create(['byok_enabled' => null]);
    $workspace = Workspace::factory()->create();

    expect((new ByokResolver)->isUnlockedFor($user, $workspace))->toBeFalse();
});

test('matrix: global OFF + user force-allows → unlocked', function () {
    setGlobalByok(false);
    $user = User::factory()->create(['byok_enabled' => true]);
    $workspace = Workspace::factory()->create();

    expect((new ByokResolver)->isUnlockedFor($user, $workspace))->toBeTrue();
});

test('matrix: global OFF + user explicit DENY → locked', function () {
    setGlobalByok(false);
    $user = User::factory()->create(['byok_enabled' => false]);
    $workspace = Workspace::factory()->create();

    expect((new ByokResolver)->isUnlockedFor($user, $workspace))->toBeFalse();
});

test('matrix: no workspace → always locked', function () {
    setGlobalByok(true);
    $user = User::factory()->create(['byok_enabled' => true]);

    expect((new ByokResolver)->isUnlockedFor($user, null))->toBeFalse();
});

test('keysFor returns the decrypted keys when present, else null', function () {
    $workspace = Workspace::factory()->create();
    expect((new ByokResolver)->keysFor($workspace))->toBeNull();

    $workspace->byok_keys = ['cloudflare_account_id' => 'cf-abc', 'cloudflare_api_token' => 'tok-xyz'];
    $workspace->save();

    $keys = (new ByokResolver)->keysFor($workspace->fresh());
    expect($keys)
        ->toHaveKey('cloudflare_account_id', 'cf-abc')
        ->toHaveKey('cloudflare_api_token', 'tok-xyz');
});

test('hasKeysFor reports provider-specific completeness', function () {
    $workspace = Workspace::factory()->create([
        'byok_keys' => [
            'cloudflare_account_id' => 'cf',
            'cloudflare_api_token' => 'tok',
            // openai key intentionally missing
        ],
    ]);

    $resolver = new ByokResolver;
    expect($resolver->hasKeysFor($workspace, 'cloudflare'))->toBeTrue();
    expect($resolver->hasKeysFor($workspace, 'openai'))->toBeFalse();
});

test('workspace keys persist encrypted at rest', function () {
    // Round-trip check: the encrypted cast turns array → ciphertext on
    // write + decrypts on read. The raw DB row should not contain the
    // plaintext token.
    $workspace = Workspace::factory()->create([
        'byok_keys' => ['openai_api_key' => 'sk-very-secret-12345'],
    ]);

    $rawRow = DB::table('workspaces')->where('id', $workspace->id)->value('byok_keys');
    expect($rawRow)->not->toContain('sk-very-secret-12345');

    // But the model getter returns the plaintext array.
    expect($workspace->fresh()->byok_keys['openai_api_key'])->toBe('sk-very-secret-12345');
});
