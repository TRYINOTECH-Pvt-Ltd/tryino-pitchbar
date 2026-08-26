<?php

use App\Services\I18n\LocaleResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->resolver = new LocaleResolver;
});

test('explicit ?locale wins over everything else', function () {
    $request = Request::create('/?locale=es', 'GET');
    $request->headers->set('Accept-Language', 'fr,en;q=0.9');

    expect($this->resolver->forRequest($request))->toBe('es');
});

test('disallowed ?locale falls through to next priority', function () {
    $request = Request::create('/?locale=zh', 'GET');
    $request->headers->set('Accept-Language', 'fr-FR,fr;q=0.9');

    expect($this->resolver->forRequest($request))->toBe('fr');
});

test('Accept-Language picks highest q-value supported match', function () {
    $request = Request::create('/', 'GET');
    // Use an obviously-unsupported pseudo-tag (xx) so the test stays
    // stable as we ship more lang JSON files. fr (0.7) wins over
    // en (0.5).
    $request->headers->set('Accept-Language', 'xx-XX,xx;q=0.8,fr;q=0.7,en;q=0.5');

    expect($this->resolver->forRequest($request))->toBe('fr');
});

test('falls back to app default when nothing matches', function () {
    config(['app.locale' => 'en']);
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept-Language', 'xx,zz;q=0.5');

    expect($this->resolver->forRequest($request))->toBe('en');
});

test('forWidget honours agent default first', function () {
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept-Language', 'fr');

    expect($this->resolver->forWidget($request, 'tr'))->toBe('tr');
});

test('forWidget falls back to Accept-Language when agent default unsupported', function () {
    $request = Request::create('/', 'GET');
    $request->headers->set('Accept-Language', 'fr-FR,fr;q=0.9');

    expect($this->resolver->forWidget($request, 'zz-unsup'))->toBe('fr');
});

test('forWidget defaults to en when nothing matches', function () {
    $request = Request::create('/', 'GET');

    expect($this->resolver->forWidget($request, null))->toBe('en');
});

test('supported() auto-discovers locales from lang/*.json', function () {
    $supported = $this->resolver->supported();

    // English must always come first (fallback contract).
    expect($supported[0])->toBe('en');
    // Plus every locale we ship a JSON dictionary for.
    foreach (['en', 'es', 'fr', 'tr'] as $known) {
        expect(in_array($known, $supported, true))->toBeTrue("Missing $known");
    }
});

test('supported() ignores non-locale files like _glossary.md', function () {
    $supported = $this->resolver->supported();

    foreach ($supported as $code) {
        expect(str_starts_with($code, '_'))->toBeFalse();
        expect(preg_match('/^[A-Za-z0-9-]{2,}$/', $code))->toBe(1);
    }
});

test('every supported locale has a JSON file', function () {
    foreach ($this->resolver->supported() as $locale) {
        expect(is_file(base_path("lang/{$locale}.json")))
            ->toBeTrue("Missing lang/{$locale}.json");
    }
});

test('dropping a new lang JSON file is picked up on next supported() call', function () {
    $tempCode = 'zz-test';
    $tempFile = base_path("lang/{$tempCode}.json");
    file_put_contents($tempFile, json_encode(['Save' => 'TestSave']));

    try {
        // Fresh resolver to avoid the per-instance memo from a prior call.
        $resolver = new LocaleResolver;
        $supported = $resolver->supported();

        expect(in_array($tempCode, $supported, true))->toBeTrue();
        expect($resolver->isSupported($tempCode))->toBeTrue();
    } finally {
        @unlink($tempFile);
    }
});
