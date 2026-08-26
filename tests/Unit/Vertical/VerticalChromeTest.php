<?php

use App\Services\Vertical\VerticalChrome;

/**
 * VerticalChrome exposes every preset's visitor-facing chrome (starter
 * chips + launcher label) so the Translation Manager can localise them.
 * Pure (no container/DB), mirrors SeoMeta::translatableStrings().
 */
test('it collects the SaaS preset starter chips and launcher label', function () {
    $strings = VerticalChrome::translatableStrings();

    expect($strings)->toContain('What does it cost?')
        ->toContain('How is this different from competitors?')
        ->toContain('Can I try it for free?')
        ->toContain('Ask about the product');
});

test('the collected strings are unique and non-empty', function () {
    $strings = VerticalChrome::translatableStrings();

    expect($strings)->toBe(array_values(array_unique($strings)))
        ->and($strings)->each->toBeString();

    foreach ($strings as $string) {
        expect(trim($string))->not->toBe('');
    }
});
