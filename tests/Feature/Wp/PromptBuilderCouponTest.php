<?php

use App\Models\Source;
use App\Services\Rag\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ecommerce agent with cached coupons gets a coupon fragment in the system prompt', function () {
    ['agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'ecommerce']);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => [
            'site_url' => 'https://shop.example.com',
            'coupons' => [
                ['code' => 'WELCOME10', 'label' => '10% off', 'discount' => '10%'],
                ['code' => 'SUMMER20', 'label' => '20% off summer', 'discount' => '20%', 'expires_at' => '2026-08-01'],
            ],
        ],
        'last_synced_at' => now(),
    ]);

    $messages = (new PromptBuilder)->build(
        agent: $agent,
        userMessage: 'Hi',
        sources: [],
        history: [],
        detectedLang: 'en',
    );

    $system = $messages[0]['content'];
    expect($system)->toContain('Available promotions');
    expect($system)->toContain('WELCOME10');
    expect($system)->toContain('SUMMER20');
    expect($system)->toContain('expires 2026-08-01');
    expect($system)->toContain('<coupon');
});

test('ecommerce agent without coupons emits no coupon fragment', function () {
    ['agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'ecommerce']);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
        'last_synced_at' => now(),
    ]);

    $messages = (new PromptBuilder)->build(
        agent: $agent,
        userMessage: 'Hi',
        sources: [],
        history: [],
        detectedLang: 'en',
    );

    expect($messages[0]['content'])->not()->toContain('Available promotions');
});

test('non-ecommerce agent never sees the coupon fragment', function () {
    ['agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'saas']);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => [
            'site_url' => 'https://shop.example.com',
            'coupons' => [['code' => 'X', 'label' => 'whatever']],
        ],
        'last_synced_at' => now(),
    ]);

    $messages = (new PromptBuilder)->build(
        agent: $agent,
        userMessage: 'Hi',
        sources: [],
        history: [],
        detectedLang: 'en',
    );

    expect($messages[0]['content'])->not()->toContain('Available promotions');
});
