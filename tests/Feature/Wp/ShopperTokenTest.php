<?php

use App\Models\WorkspaceApiToken;
use App\Services\Widget\ShopperToken;
use App\Support\HmacSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('issue then verifyWithSecret round-trips claims', function () {
    $secret = Str::random(48);
    $token = ShopperToken::issue([
        'wp_user_id' => '42',
        'email_hash' => str_repeat('a', 64),
        'source' => ShopperToken::SOURCE_WORDPRESS,
    ], $secret);

    $claims = ShopperToken::verifyWithSecret($token, $secret);
    expect($claims)->not()->toBeNull();
    expect($claims['wp_user_id'])->toBe('42');
    expect($claims['email_hash'])->toBe(str_repeat('a', 64));
    expect($claims['source'])->toBe('wordpress');
});

test('verifyWithSecret rejects a different secret', function () {
    $token = ShopperToken::issue(['wp_user_id' => '7'], 'secret-a');
    expect(ShopperToken::verifyWithSecret($token, 'secret-b'))->toBeNull();
});

test('verifyWithSecret rejects a tampered payload', function () {
    $secret = Str::random(48);
    $token = ShopperToken::issue(['wp_user_id' => '7'], $secret);
    [$encoded, $sig] = explode('.', $token, 2);
    // Forge a different payload (different wp_user_id) but keep the old signature.
    $forged = rtrim(strtr(base64_encode('{"wp_user_id":"99","email_hash":"","source":"wordpress"}'), '+/', '-_'), '=');
    $tampered = $forged.'.'.$sig;

    expect(ShopperToken::verifyWithSecret($tampered, $secret))->toBeNull();
});

test('verifyForWorkspace picks the matching API token signing secret', function () {
    ['workspace' => $workspace] = workspaceMember();
    $secretA = Str::random(48);
    $secretB = Str::random(48);

    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => $secretA,
    ]);
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => $secretB,
    ]);

    $tokenA = ShopperToken::issue(['wp_user_id' => '11'], $secretA);
    $tokenB = ShopperToken::issue(['wp_user_id' => '22'], $secretB);

    expect(ShopperToken::verifyForWorkspace($tokenA, $workspace->id))
        ->not()->toBeNull()
        ->and(ShopperToken::verifyForWorkspace($tokenA, $workspace->id)['wp_user_id'])->toBe('11');

    expect(ShopperToken::verifyForWorkspace($tokenB, $workspace->id))
        ->not()->toBeNull()
        ->and(ShopperToken::verifyForWorkspace($tokenB, $workspace->id)['wp_user_id'])->toBe('22');
});

test('verifyForWorkspace returns null when no active token matches', function () {
    ['workspace' => $workspace] = workspaceMember();

    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => Str::random(48),
    ]);

    $forged = ShopperToken::issue(['wp_user_id' => '1'], 'unknown-secret');
    expect(ShopperToken::verifyForWorkspace($forged, $workspace->id))->toBeNull();
});

test('verifyForWorkspace ignores revoked tokens', function () {
    ['workspace' => $workspace] = workspaceMember();
    $secret = Str::random(48);
    WorkspaceApiToken::factory()->revoked()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => $secret,
    ]);

    $token = ShopperToken::issue(['wp_user_id' => '1'], $secret);
    expect(ShopperToken::verifyForWorkspace($token, $workspace->id))->toBeNull();
});

test('verifyWithSecret rejects a signature outside the replay window', function () {
    $secret = Str::random(48);
    $claims = ['wp_user_id' => '1', 'email_hash' => '', 'source' => 'wordpress'];
    $json = json_encode($claims, JSON_THROW_ON_ERROR);
    $expired = time() - HmacSignature::REPLAY_WINDOW_SECONDS - 60;
    $sig = HmacSignature::sign($secret, $json, $expired);
    $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $token = $encoded.'.'.$sig;

    expect(ShopperToken::verifyWithSecret($token, $secret))->toBeNull();
});
