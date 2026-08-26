<?php

use App\Services\Vector\EmbedModelDimensions;

test('known Cloudflare embed models resolve to their native dims', function () {
    expect(EmbedModelDimensions::forModel('@cf/baai/bge-small-en-v1.5'))->toBe(384);
    expect(EmbedModelDimensions::forModel('@cf/baai/bge-base-en-v1.5'))->toBe(768);
    expect(EmbedModelDimensions::forModel('@cf/baai/bge-large-en-v1.5'))->toBe(1024);
    expect(EmbedModelDimensions::forModel('@cf/baai/bge-m3'))->toBe(1024);
});

test('known OpenAI embed models resolve to their native dims', function () {
    expect(EmbedModelDimensions::forModel('text-embedding-3-small'))->toBe(1536);
    expect(EmbedModelDimensions::forModel('text-embedding-3-large'))->toBe(3072);
    expect(EmbedModelDimensions::forModel('text-embedding-ada-002'))->toBe(1536);
});

test('unknown / null / empty model returns null', function () {
    expect(EmbedModelDimensions::forModel(null))->toBeNull();
    expect(EmbedModelDimensions::forModel(''))->toBeNull();
    expect(EmbedModelDimensions::forModel('some-future-model-v99'))->toBeNull();
});

test('resolveExpectedDim picks Cloudflare model dim by default', function () {
    config()->set('services.cloudflare.embed_model', '@cf/baai/bge-m3');
    config()->set('services.vector_dim', 768);
    config()->set('services.llm_provider', 'cloudflare');

    // VECTOR_DIM env not set → model map wins → 1024 (bge-m3)
    expect(EmbedModelDimensions::resolveExpectedDim())->toBe(1024);
});

test('resolveExpectedDim picks OpenAI model dim when provider is openai', function () {
    config()->set('services.openai.embed_model', 'text-embedding-3-large');
    config()->set('services.llm_provider', 'openai');

    expect(EmbedModelDimensions::resolveExpectedDim())->toBe(3072);
});

test('resolveExpectedDim falls back to services.vector_dim when model unknown', function () {
    config()->set('services.cloudflare.embed_model', 'some-unknown-model');
    config()->set('services.vector_dim', 512);
    config()->set('services.llm_provider', 'cloudflare');

    expect(EmbedModelDimensions::resolveExpectedDim())->toBe(512);
});
