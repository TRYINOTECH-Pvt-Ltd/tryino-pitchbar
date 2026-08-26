<?php

use App\Services\Widget\AcceptLanguage;

test('returns null when header is missing', function () {
    expect(AcceptLanguage::detect(null))->toBeNull();
    expect(AcceptLanguage::detect(''))->toBeNull();
});

test('returns the fallback when header is missing', function () {
    expect(AcceptLanguage::detect(null, fallback: 'en'))->toBe('en');
});

test('extracts the primary subtag', function () {
    expect(AcceptLanguage::detect('en-US'))->toBe('en');
    expect(AcceptLanguage::detect('fr-CA'))->toBe('fr');
});

test('picks the highest-quality language from a comma list', function () {
    // Default q for first item is 1.0, then 0.9, 0.8, 0.7
    expect(AcceptLanguage::detect('fr;q=0.7,es;q=0.9,de;q=0.8'))->toBe('es');
});

test('skips languages we do not support', function () {
    // Klingon (tlh) and Esperanto (eo) are not supported; fr is.
    expect(AcceptLanguage::detect('tlh-KR;q=1.0,eo;q=0.9,fr;q=0.5'))->toBe('fr');
});

test('returns the fallback when no supported language matches', function () {
    expect(AcceptLanguage::detect('tlh,eo;q=0.5', fallback: 'en'))->toBe('en');
});

test('handles whitespace and casing', function () {
    expect(AcceptLanguage::detect('  EN-US ; q=0.9 ,  FR ; q=0.8 '))->toBe('en');
});
