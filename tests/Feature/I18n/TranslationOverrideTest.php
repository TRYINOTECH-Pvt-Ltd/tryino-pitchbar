<?php

use App\Enums\PlatformRole;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\I18n\TranslationLoader;
use App\Services\I18n\TranslationOverrides;

beforeEach(function () {
    TranslationOverride::query()->delete();
    app(TranslationOverrides::class)->bump();
    // The manager is gated behind the multilingual flag (default off);
    // enable it so these manager tests can reach the endpoints.
    config(['app.multilingual_enabled' => true]);
    $this->withoutVite();
});

function transAdmin(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

// ── Resolution layer ────────────────────────────────────────────────

test('an override wins over the shipped file value in jsonFor and get', function () {
    $overrides = app(TranslationOverrides::class);
    $loader = app(TranslationLoader::class);

    expect($loader->get('nl', 'Save'))->toBe('Opslaan'); // shipped file value

    $overrides->put('nl', 'Save', 'BEWAARD');

    expect($loader->get('nl', 'Save'))->toBe('BEWAARD')
        ->and($loader->jsonFor('nl')['Save'])->toBe('BEWAARD');
});

test('forget restores the shipped file value and the version bump invalidates the cache', function () {
    $overrides = app(TranslationOverrides::class);
    $loader = app(TranslationLoader::class);

    $overrides->put('nl', 'Save', 'BEWAARD');
    expect($loader->jsonFor('nl')['Save'])->toBe('BEWAARD');

    $overrides->forget('nl', 'Save');
    expect($loader->jsonFor('nl')['Save'])->toBe('Opslaan');
});

test('countsByLocale reflects writes', function () {
    $overrides = app(TranslationOverrides::class);
    $overrides->put('nl', 'Save', 'A');
    $overrides->put('nl', 'Cancel', 'B');
    $overrides->put('de', 'Save', 'C');

    expect($overrides->countsByLocale())->toMatchArray(['nl' => 2, 'de' => 1]);
});

// ── The bare __() helper is override-aware too ──────────────────────

test('the __() helper reflects an override', function () {
    app(TranslationOverrides::class)->put('nl', 'Save', 'BEWAARD');
    app()->setLocale('nl');

    expect(__('Save'))->toBe('BEWAARD');
})->skip(fn () => str_contains(strtolower(config('octane.server', '')), 'frankenphp'), 'Translator memoization is per-process under Octane.');

// ── Admin endpoints ─────────────────────────────────────────────────

test('the index lists locales with translated/total/missing/override counts', function () {
    app(TranslationOverrides::class)->put('nl', 'Save', 'BEWAARD');

    $this->actingAs(transAdmin())
        ->get('/admin/translations')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('admin/translations/index')
            ->where('locales', fn ($locales) => collect($locales)->firstWhere('code', 'nl')['overrides'] === 1)
            ->etc());
});

test('the per-locale editor lists keys and supports the overridden filter', function () {
    app(TranslationOverrides::class)->put('nl', 'Save', 'BEWAARD');

    $this->actingAs(transAdmin())
        ->get('/admin/translations/nl?filter=overridden')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('admin/translations/edit')
            ->where('rows', fn ($rows) => count($rows) === 1
                && $rows[0]['key'] === 'Save'
                && $rows[0]['value'] === 'BEWAARD'
                && $rows[0]['is_overridden'] === true)
            ->etc());
});

test('search filters the editor rows', function () {
    $this->actingAs(transAdmin())
        ->get('/admin/translations/nl?search=Save')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('rows', fn ($rows) => collect($rows)->every(
                fn ($r) => str_contains(strtolower($r['key'].$r['source'].$r['value']), 'save')
            ))
            ->etc());
});

test('update upserts an override and it is reflected immediately', function () {
    $this->actingAs(transAdmin())
        ->put('/admin/translations/nl', ['key' => 'Save', 'value' => 'BEWAARD'])
        ->assertRedirect();

    expect(app(TranslationLoader::class)->jsonFor('nl')['Save'])->toBe('BEWAARD');
});

test('update rejects a key that is not in the source file', function () {
    $this->actingAs(transAdmin())
        ->put('/admin/translations/nl', ['key' => 'This key does not exist anywhere', 'value' => 'X'])
        ->assertStatus(422);
});

test('destroy resets an override', function () {
    app(TranslationOverrides::class)->put('nl', 'Save', 'BEWAARD');

    $this->actingAs(transAdmin())
        ->delete('/admin/translations/nl', ['key' => 'Save'])
        ->assertRedirect();

    expect(TranslationOverride::query()->count())->toBe(0)
        ->and(app(TranslationLoader::class)->jsonFor('nl')['Save'])->toBe('Opslaan');
});

test('an unknown locale 404s', function () {
    $this->actingAs(transAdmin())
        ->get('/admin/translations/not-a-locale')
        ->assertNotFound();
});

// ── Marketing content reflects overrides ────────────────────────────

test('an override changes the localised marketing content', function () {
    // Marketing strings are translated server-side via MarketingTranslator,
    // which now resolves through the override-aware loader.
    app(TranslationOverrides::class)->put('nl', 'Turn every', 'OVERRIDE_HERO_NL');

    $this->get('/?locale=nl')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('content.hero.line_one', 'OVERRIDE_HERO_NL')
            ->etc());
});

// ── Access control ──────────────────────────────────────────────────

test('a customer cannot reach the translation manager', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $this->actingAs($customer)->get('/admin/translations')->assertNotFound();
});

test('a guest is redirected to login', function () {
    $this->get('/admin/translations')->assertRedirect('/login');
});
