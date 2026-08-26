<?php

use App\Services\I18n\LocaleCatalog;

test('catalog ships at least 120 popular languages', function () {
    expect(count(LocaleCatalog::ENTRIES))->toBeGreaterThanOrEqual(120);
});

test('every catalog entry has the required shape', function () {
    foreach (LocaleCatalog::ENTRIES as $code => $entry) {
        expect($entry)->toHaveKeys(['native', 'english', 'flag', 'rtl']);
        expect($entry['native'])->toBeString()->not->toBeEmpty();
        expect($entry['english'])->toBeString()->not->toBeEmpty();
        expect($entry['flag'])->toBeString()->not->toBeEmpty();
        expect($entry['rtl'])->toBeBool();
        // BCP-47-ish: letters/digits/hyphen.
        expect(preg_match('/^[A-Za-z0-9-]{2,}$/', $code))->toBe(1);
    }
});

test('major RTL languages are flagged as RTL', function () {
    foreach (['ar', 'fa', 'he', 'ur', 'ps', 'dv'] as $rtl) {
        expect(LocaleCatalog::ENTRIES[$rtl]['rtl'])->toBeTrue("$rtl should be RTL");
    }
});

test('entryFor falls back gracefully for unknown codes', function () {
    $entry = LocaleCatalog::entryFor('zz-unknown');

    expect($entry['native'])->toBe('zz-unknown');
    expect($entry['flag'])->toBe('🌐');
    expect($entry['rtl'])->toBeFalse();
});

test('entryFor honours primary subtag when full tag is unknown', function () {
    // We catalogue `pt-BR` but not `pt-PT`; falling back to the primary
    // subtag means a buyer dropping `lang/pt-PT.json` still gets a
    // sensible Portuguese flag/native name.
    $entry = LocaleCatalog::entryFor('pt-PT');

    expect($entry['english'])->toBe('Portuguese');
});

test('hydrate shapes the catalog into the API contract the frontend expects', function () {
    $hydrated = LocaleCatalog::hydrate(['en', 'es', 'ar']);

    expect($hydrated)->toHaveKeys(['en', 'es', 'ar']);
    foreach ($hydrated as $code => $entry) {
        expect($entry['code'])->toBe($code);
        expect($entry)->toHaveKeys(['code', 'native', 'english', 'flag', 'rtl']);
    }
    expect($hydrated['ar']['rtl'])->toBeTrue();
});
