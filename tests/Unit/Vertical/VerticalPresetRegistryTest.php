<?php

use App\Services\Vertical\VerticalPresetRegistry;
use App\Services\Vertical\VerticalPresets;

beforeEach(function () {
    $this->registry = new VerticalPresetRegistry;
});

test('every known slug returns a non-null preset with matching slug', function () {
    foreach (VerticalPresets::SLUGS as $slug) {
        $preset = $this->registry->for($slug);
        expect($preset->slug())->toBe($slug);
    }
});

test('unknown slug falls back to generic', function () {
    $preset = $this->registry->for('not-a-real-slug');
    expect($preset->slug())->toBe('generic');
});

test('all() returns one entry per slug', function () {
    expect(array_keys($this->registry->all()))->toBe(VerticalPresets::SLUGS);
});
