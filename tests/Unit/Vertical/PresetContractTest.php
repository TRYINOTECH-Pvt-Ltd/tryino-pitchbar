<?php

use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Vertical\VerticalPresets;

dataset('presets', function () {
    $registry = new VerticalPresetRegistry;
    foreach (VerticalPresets::SLUGS as $slug) {
        yield $slug => [$registry->for($slug)];
    }
});

test('slug is in the allow-list', function ($preset) {
    expect(VerticalPresets::SLUGS)->toContain($preset->slug());
})->with('presets');

test('label and shortDescription are non-empty strings', function ($preset) {
    expect($preset->label())->toBeString()->not->toBe('');
    expect($preset->shortDescription())->toBeString()->not->toBe('');
})->with('presets');

test('starter prompts cap at 6 and each <= 80 chars', function ($preset) {
    $prompts = $preset->starterPrompts();
    expect($prompts)->toBeArray();
    expect(count($prompts))->toBeLessThanOrEqual(6);
    foreach ($prompts as $p) {
        expect(strlen($p))->toBeLessThanOrEqual(80);
    }
})->with('presets');

test('maxChars within sane bounds', function ($preset) {
    expect($preset->maxChars())->toBeGreaterThanOrEqual(800);
    expect($preset->maxChars())->toBeLessThanOrEqual(4000);
})->with('presets');

test('capabilities are snake_case strings', function ($preset) {
    foreach ($preset->capabilities() as $c) {
        expect($c)->toBeString();
        expect($c)->toMatch('/^[a-z][a-z0-9_]*$/');
    }
})->with('presets');

test('retrievalTuning has the expected shape', function ($preset) {
    $tuning = $preset->retrievalTuning();
    expect($tuning)->toHaveKeys(['boost_keywords', 'chunk_overlap_bias']);
    expect($tuning['boost_keywords'])->toBeArray();
    expect($tuning['chunk_overlap_bias'])->toBeFloat();
})->with('presets');

test('non-generic presets ship a non-empty system prompt fragment', function ($preset) {
    if ($preset->slug() === 'generic') {
        expect($preset->systemPromptFragment())->toBe('');

        return;
    }
    expect(trim($preset->systemPromptFragment()))->not->toBe('');
})->with('presets');
