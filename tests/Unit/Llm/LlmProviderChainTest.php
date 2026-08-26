<?php

use App\Services\Llm\LlmProviderChain;

// $provider, hasCloudflare, hasOpenAi, hasOpenRouter → expected ordered names
dataset('provider chains', [
    'nothing configured' => ['', false, false, false, []],

    // REGRESSION GUARD: a lone/stale OPENROUTER_API_KEY (no explicit provider,
    // no other provider) must NOT bind — it would 401 "User not found" and
    // hard-fail every stream. Falls through to the fake instead.
    'lone stale openrouter key' => ['', false, false, true, []],

    // Explicit selection enrolls OpenRouter even when it's the only key.
    'explicit openrouter' => ['openrouter', false, false, true, ['openrouter']],

    'cloudflare only' => ['', true, false, false, ['cloudflare']],
    'openai only' => ['', false, true, false, ['openai']],

    // OpenRouter joins as a fallback when a real primary exists.
    'cloudflare + openrouter fallback' => ['', true, false, true, ['cloudflare', 'openrouter']],
    'openai + openrouter fallback' => ['', false, true, true, ['openai', 'openrouter']],

    // Default precedence cloudflare → openai → openrouter.
    'all three, no explicit' => ['', true, true, true, ['cloudflare', 'openai', 'openrouter']],

    // Explicit provider leads, the rest follow in precedence.
    'explicit openai leads' => ['openai', true, true, true, ['openai', 'cloudflare', 'openrouter']],
    'explicit cloudflare' => ['cloudflare', true, true, false, ['cloudflare', 'openai']],

    // Explicit selection of an UNconfigured provider is ignored.
    'explicit openrouter but only cloudflare key' => ['openrouter', true, false, false, ['cloudflare']],
]);

it('orders the failover chain correctly', function (
    string $provider,
    bool $hasCloudflare,
    bool $hasOpenAi,
    bool $hasOpenRouter,
    array $expected,
) {
    expect(LlmProviderChain::order($provider, $hasCloudflare, $hasOpenAi, $hasOpenRouter))
        ->toBe($expected);
})->with('provider chains');

// order names → concrete failover entries, with Cloudflare split into a
// primary + fallback CHAT model. $order, cfPrimary, cfFallback → entries.
dataset('cloudflare model fallback entries', [
    // The headline case: a single-Cloudflare install gains a second,
    // faster model entry → self-heal with no other provider.
    'cloudflare splits into primary + fallback model' => [
        ['cloudflare'], '@cf/meta/llama-3.3-70b-instruct-fp8-fast', '@cf/meta/llama-3.1-8b-instruct',
        [
            ['name' => 'cloudflare', 'cloudflare_model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
            ['name' => 'cloudflare-fallback', 'cloudflare_model' => '@cf/meta/llama-3.1-8b-instruct'],
        ],
    ],

    // No fallback configured → bare single entry (today's behaviour).
    'empty fallback model leaves a single cloudflare entry' => [
        ['cloudflare'], '@cf/meta/llama-3.3-70b-instruct-fp8-fast', '',
        [['name' => 'cloudflare', 'cloudflare_model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast']],
    ],

    // Same model for both → no pointless duplicate (a same-model retry
    // hits the same failure).
    'fallback identical to primary is deduped' => [
        ['cloudflare'], '@cf/meta/llama-3.1-8b-instruct', '@cf/meta/llama-3.1-8b-instruct',
        [['name' => 'cloudflare', 'cloudflare_model' => '@cf/meta/llama-3.1-8b-instruct']],
    ],

    // CF fallback slots in BEFORE any cross-provider hop: CF → CF → OpenAI.
    'cloudflare fallback precedes a cross-provider hop' => [
        ['cloudflare', 'openai'], '70b', '8b',
        [
            ['name' => 'cloudflare', 'cloudflare_model' => '70b'],
            ['name' => 'cloudflare-fallback', 'cloudflare_model' => '8b'],
            ['name' => 'openai', 'cloudflare_model' => null],
        ],
    ],

    // No Cloudflare in the order → the CF fallback model is irrelevant.
    'non-cloudflare order ignores the cloudflare fallback model' => [
        ['openai', 'openrouter'], '70b', '8b',
        [
            ['name' => 'openai', 'cloudflare_model' => null],
            ['name' => 'openrouter', 'cloudflare_model' => null],
        ],
    ],

    'empty order yields no entries' => [[], '70b', '8b', []],
]);

it('expands provider order into failover entries, splitting cloudflare by model', function (
    array $order,
    string $cfPrimary,
    string $cfFallback,
    array $expected,
) {
    expect(LlmProviderChain::entries($order, $cfPrimary, $cfFallback))->toBe($expected);
})->with('cloudflare model fallback entries');
