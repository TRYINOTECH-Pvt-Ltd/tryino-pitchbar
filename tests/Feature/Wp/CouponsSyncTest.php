<?php

use App\Models\Source;
use App\Models\WorkspaceApiToken;
use App\Support\HmacSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function couponToken(string $workspaceId): string
{
    $plaintext = 'pbar_'.Str::random(48);
    WorkspaceApiToken::create([
        'workspace_id' => $workspaceId,
        'name' => 'coupon test',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => ['wp:integration'],
        'shopper_signing_secret' => Str::random(48),
    ]);

    return $plaintext;
}

function signedCouponPost(string $bearer, array $body): TestResponse
{
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $signature = HmacSignature::sign($bearer, $json);

    return test()->call('POST', '/api/v1/wp/coupons/sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        'HTTP_X_PITCHBAR_SIGNATURE' => $signature,
    ], $json);
}

test('coupons sync writes the coupons array into the matching source.config', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
        'last_synced_at' => now(),
    ]);
    $bearer = couponToken($workspace->id);

    signedCouponPost($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.2.0',
        'coupons' => [
            ['code' => 'WELCOME10', 'label' => '10% off', 'discount' => '10%', 'expires_at' => null],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    $source = Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->sole();
    expect($source->config['coupons'])->toHaveCount(1);
    expect($source->config['coupons'][0]['code'])->toBe('WELCOME10');
});

test('coupons sync 404s when no woocommerce source matches the site host', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = couponToken($workspace->id);

    signedCouponPost($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.2.0',
        'coupons' => [],
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'source_not_found');
});

test('coupons sync rejects a foreign agent_id', function () {
    ['workspace' => $workspaceA] = workspaceMemberWithAgent();
    ['agent' => $agentB] = workspaceMemberWithAgent();
    $bearer = couponToken($workspaceA->id);

    signedCouponPost($bearer, [
        'agent_id' => $agentB->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.2.0',
        'coupons' => [],
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'agent_not_found');
});
