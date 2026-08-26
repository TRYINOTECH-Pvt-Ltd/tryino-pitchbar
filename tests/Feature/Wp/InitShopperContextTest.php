<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\WorkspaceApiToken;
use App\Services\Widget\ShopperToken;
use App\Services\Widget\WidgetJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function publishedEcommerceAgentForShopperTest(): Agent
{
    ['workspace' => $workspace] = workspaceMember();
    /** @var Agent $agent */
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'is_published' => true,
        'site_type' => 'ecommerce',
        'allowed_origins' => ['*'],
    ]);

    return $agent;
}

test('valid shopper_token bakes claims into the issued widget JWT', function () {
    $agent = publishedEcommerceAgentForShopperTest();
    $secret = Str::random(48);
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $agent->workspace_id,
        'shopper_signing_secret' => $secret,
    ]);

    $shopperToken = ShopperToken::issue([
        'wp_user_id' => '500',
        'email_hash' => str_repeat('b', 64),
    ], $secret);

    $response = $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
        'shopper_token' => $shopperToken,
    ], ['Origin' => 'https://shop.example.com']);

    $response->assertOk();

    $jwt = $response->json('data.jwt');
    // Decode via the container-bound WidgetJwt so the test inherits
    // the provider's sha256-derived signing key — firebase/php-jwt
    // v6.10+ rejects keys shorter than 32 bytes, and the provider
    // sha256s the configured WIDGET_JWT_SECRET unconditionally to
    // meet that bar.
    $decoded = app(WidgetJwt::class)->verify($jwt);
    expect($decoded['shopper'])->not()->toBeNull();
    expect((array) $decoded['shopper'])->toMatchArray([
        'wp_user_id' => '500',
        'email_hash' => str_repeat('b', 64),
        'source' => 'wordpress',
    ]);

    $conversationId = $response->json('data.conversation_id');
    /** @var Conversation $conversation */
    $conversation = Conversation::query()->withoutGlobalScopes()->findOrFail($conversationId);
    expect((array) ($conversation->attribution['shopper'] ?? []))->toMatchArray([
        'wp_user_id' => '500',
        'email_hash' => str_repeat('b', 64),
        'source' => 'wordpress',
    ]);
});

test('invalid shopper_token is silently dropped — visitor stays anonymous', function () {
    $agent = publishedEcommerceAgentForShopperTest();
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $agent->workspace_id,
        'shopper_signing_secret' => Str::random(48),
    ]);

    // Forged token signed with a secret the workspace doesn't know.
    $forged = ShopperToken::issue(['wp_user_id' => '9'], Str::random(48));

    $response = $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
        'shopper_token' => $forged,
    ], ['Origin' => 'https://shop.example.com']);

    $response->assertOk();
    $jwt = $response->json('data.jwt');
    $decoded = app(WidgetJwt::class)->verify($jwt);
    expect($decoded)->not()->toHaveKey('shopper');
});

test('missing shopper_token leaves the existing anonymous flow intact', function () {
    $agent = publishedEcommerceAgentForShopperTest();

    $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
    ], ['Origin' => 'https://shop.example.com'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['jwt', 'conversation_id']]);
});
