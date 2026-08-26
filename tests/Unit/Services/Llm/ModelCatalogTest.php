<?php

use App\Services\Llm\ModelCatalog;

it('returns the full catalogue', function () {
    $catalog = new ModelCatalog;

    expect($catalog->all())->toBeArray()
        ->and(count($catalog->all()))->toBeGreaterThan(60);
});

it('covers every major provider with at least 20 entries', function () {
    $catalog = new ModelCatalog;

    expect(count($catalog->forProvider(ModelCatalog::PROVIDER_CLOUDFLARE)))->toBeGreaterThanOrEqual(30)
        ->and(count($catalog->forProvider(ModelCatalog::PROVIDER_OPENAI)))->toBeGreaterThanOrEqual(20)
        ->and(count($catalog->forProvider(ModelCatalog::PROVIDER_OPENROUTER)))->toBeGreaterThanOrEqual(25);
});

it('returns Cloudflare embed models with required keys', function () {
    $catalog = new ModelCatalog;

    $entries = $catalog->embedModelsForProvider(ModelCatalog::PROVIDER_CLOUDFLARE);

    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys([
            'id', 'label', 'provider', 'dimensions', 'max_input_tokens',
            'languages', 'cost', 'recommended', 'notes',
        ])
            ->and($entry['provider'])->toBe(ModelCatalog::PROVIDER_CLOUDFLARE)
            ->and($entry['dimensions'])->toBeInt()->toBeGreaterThan(0)
            ->and($entry['max_input_tokens'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('returns OpenAI embed models with required keys', function () {
    $catalog = new ModelCatalog;

    $entries = $catalog->embedModelsForProvider(ModelCatalog::PROVIDER_OPENAI);

    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect($entry['provider'])->toBe(ModelCatalog::PROVIDER_OPENAI)
            ->and($entry['dimensions'])->toBeInt()->toBeGreaterThan(0);
    }

    $ids = array_map(fn ($e) => $e['id'], $entries);
    expect($ids)->toContain('text-embedding-3-small')
        ->and($ids)->toContain('text-embedding-3-large')
        ->and($ids)->toContain('text-embedding-ada-002');
});

it('returns OpenRouter embed models proxied via OpenAI ids', function () {
    $catalog = new ModelCatalog;

    $entries = $catalog->embedModelsForProvider(ModelCatalog::PROVIDER_OPENROUTER);
    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect($entry['provider'])->toBe(ModelCatalog::PROVIDER_OPENROUTER);
    }
});

it('returns empty embed catalogue for an unknown provider', function () {
    $catalog = new ModelCatalog;

    expect($catalog->embedModelsForProvider('made-up-provider'))->toBe([]);
});

it('every Cloudflare embed entry exists on the verified live list', function () {
    // Snapshot from developers.cloudflare.com/workers-ai/models/
    // (audited 2026-06-09). Reranker excluded — it has a different
    // task shape.
    $verifiedEmbed = [
        '@cf/baai/bge-base-en-v1.5',
        '@cf/baai/bge-large-en-v1.5',
        '@cf/baai/bge-m3',
        '@cf/baai/bge-small-en-v1.5',
        '@cf/google/embeddinggemma-300m',
        '@cf/pfnet/plamo-embedding-1b',
        '@cf/qwen/qwen3-embedding-0.6b',
    ];

    $catalog = new ModelCatalog;
    $catalogIds = array_map(
        fn ($e) => $e['id'],
        $catalog->embedModelsForProvider(ModelCatalog::PROVIDER_CLOUDFLARE),
    );

    $unverified = array_diff($catalogIds, $verifiedEmbed);
    expect($unverified)->toBe([], 'Embed catalogue contains Cloudflare IDs not on the verified live list: '.implode(', ', $unverified));
});

it('every Cloudflare entry exists on the verified live list', function () {
    // Snapshot of the canonical text-generation model IDs from
    // developers.cloudflare.com/workers-ai/models/ (audited 2026-06-09).
    // If Cloudflare adds a new model, append it here AND add a
    // ModelCatalog entry — the "Refresh from Cloudflare" UI also
    // surfaces it at runtime without a code change. If Cloudflare
    // deprecates a model, drop it from this list AND from the catalog.
    $verified = [
        '@cf/aisingapore/gemma-sea-lion-v4-27b-it',
        '@cf/deepseek-ai/deepseek-r1-distill-qwen-32b',
        '@cf/defog/sqlcoder-7b-2',
        '@cf/google/gemma-2b-it-lora',
        '@cf/google/gemma-3-12b-it',
        '@cf/google/gemma-4-26b-a4b-it',
        '@cf/google/gemma-7b-it-lora',
        '@cf/ibm-granite/granite-4.0-h-micro',
        '@cf/meta-llama/llama-2-7b-chat-hf-lora',
        '@cf/meta/llama-2-7b-chat-fp16',
        '@cf/meta/llama-2-7b-chat-int8',
        '@cf/meta/llama-3-8b-instruct',
        '@cf/meta/llama-3-8b-instruct-awq',
        '@cf/meta/llama-3.1-70b-instruct',
        '@cf/meta/llama-3.1-8b-instruct',
        '@cf/meta/llama-3.1-8b-instruct-awq',
        '@cf/meta/llama-3.1-8b-instruct-fast',
        '@cf/meta/llama-3.1-8b-instruct-fp8',
        '@cf/meta/llama-3.2-11b-vision-instruct',
        '@cf/meta/llama-3.2-1b-instruct',
        '@cf/meta/llama-3.2-3b-instruct',
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        '@cf/meta/llama-4-scout-17b-16e-instruct',
        '@cf/meta/llama-guard-3-8b',
        '@cf/microsoft/phi-2',
        '@cf/mistral/mistral-7b-instruct-v0.1',
        '@cf/mistral/mistral-7b-instruct-v0.2-lora',
        '@cf/mistralai/mistral-small-3.1-24b-instruct',
        '@cf/moonshotai/kimi-k2.5',
        '@cf/moonshotai/kimi-k2.6',
        '@cf/nvidia/nemotron-3-120b-a12b',
        '@cf/openai/gpt-oss-120b',
        '@cf/openai/gpt-oss-20b',
        '@cf/qwen/qwen2.5-coder-32b-instruct',
        '@cf/qwen/qwen3-30b-a3b-fp8',
        '@cf/qwen/qwq-32b',
        '@cf/zai-org/glm-4.7-flash',
    ];

    $catalog = new ModelCatalog;
    $catalogIds = array_map(
        fn ($e) => $e['id'],
        $catalog->forProvider(ModelCatalog::PROVIDER_CLOUDFLARE),
    );

    $unverified = array_diff($catalogIds, $verified);
    expect($unverified)->toBe([], 'Catalogue contains Cloudflare model IDs not on the verified live list: '.implode(', ', $unverified));
});

it('every catalogue id is unique across providers', function () {
    $catalog = new ModelCatalog;

    $ids = array_map(fn ($e) => $e['id'], $catalog->all());
    expect(count($ids))->toBe(count(array_unique($ids)));
});

it('groups entries by provider', function () {
    $catalog = new ModelCatalog;

    $cf = $catalog->forProvider(ModelCatalog::PROVIDER_CLOUDFLARE);
    $openai = $catalog->forProvider(ModelCatalog::PROVIDER_OPENAI);
    $openrouter = $catalog->forProvider(ModelCatalog::PROVIDER_OPENROUTER);

    expect($cf)->not->toBeEmpty()
        ->and($openai)->not->toBeEmpty()
        ->and($openrouter)->not->toBeEmpty();

    foreach ($cf as $entry) {
        expect($entry['provider'])->toBe(ModelCatalog::PROVIDER_CLOUDFLARE);
    }
    foreach ($openai as $entry) {
        expect($entry['provider'])->toBe(ModelCatalog::PROVIDER_OPENAI);
    }
    foreach ($openrouter as $entry) {
        expect($entry['provider'])->toBe(ModelCatalog::PROVIDER_OPENROUTER);
    }
});

it('finds a model by id', function () {
    $catalog = new ModelCatalog;

    $found = $catalog->find('gpt-4o-mini');

    expect($found)->not->toBeNull()
        ->and($found['provider'])->toBe(ModelCatalog::PROVIDER_OPENAI)
        ->and($found['tier'])->toBe(ModelCatalog::TIER_FAST);
});

it('returns null when finding an unknown id', function () {
    $catalog = new ModelCatalog;

    expect($catalog->find('does-not-exist'))->toBeNull();
});

it('every entry exposes the expected keys', function () {
    $catalog = new ModelCatalog;

    foreach ($catalog->all() as $entry) {
        expect($entry)->toHaveKeys([
            'id', 'label', 'provider', 'ttft_ms', 'tier', 'cost',
            'context_tokens', 'supports_tools', 'recommended', 'notes',
        ])
            ->and($entry['ttft_ms'])->toBeInt()->toBeGreaterThan(0)
            ->and($entry['tier'])->toBeIn([
                ModelCatalog::TIER_FAST,
                ModelCatalog::TIER_MEDIUM,
                ModelCatalog::TIER_SLOW,
            ]);
    }
});

it('exposes at least one recommended model per provider', function () {
    $catalog = new ModelCatalog;

    foreach ([
        ModelCatalog::PROVIDER_CLOUDFLARE,
        ModelCatalog::PROVIDER_OPENAI,
        ModelCatalog::PROVIDER_OPENROUTER,
    ] as $provider) {
        $recommended = array_filter(
            $catalog->forProvider($provider),
            fn ($e) => $e['recommended'] === true,
        );
        expect($recommended)->not->toBeEmpty();
    }
});
