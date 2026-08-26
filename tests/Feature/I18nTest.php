<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\I18n\LocaleResolver;

test('Inertia shared props include the i18n bundle', function () {
    $user = User::factory()->create(['locale' => 'es']);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('i18n.locale', 'es')
        ->where('i18n.fallback', 'en')
        ->has('i18n.translations')
    );
});

test('SetLocale middleware honours explicit ?locale query', function () {
    $user = User::factory()->create(['locale' => 'en']);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/dashboard?locale=fr');

    $response->assertInertia(fn ($page) => $page->where('i18n.locale', 'fr'));
});

test('SetLocale middleware reads users.locale when no override', function () {
    $user = User::factory()->create(['locale' => 'tr']);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('i18n.locale', 'tr'));
});

test('Accept-Language drives the locale for unauthenticated requests', function () {
    $response = $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5'])
        ->get('/login');

    $response->assertOk();
    expect(app()->getLocale())->toBe('fr');
});

test('disallowed ?locale silently falls through', function () {
    $user = User::factory()->create(['locale' => 'es']);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)->get('/dashboard?locale=zz')
        ->assertInertia(fn ($page) => $page->where('i18n.locale', 'es'));
});

test('PATCH /settings/locale persists the user choice', function () {
    $user = User::factory()->create(['locale' => null]);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)
        ->patch('/settings/locale', ['locale' => 'fr'])
        ->assertRedirect();

    expect($user->fresh()->locale)->toBe('fr');
});

test('PATCH /settings/locale rejects unsupported locales', function () {
    $user = User::factory()->create(['locale' => null]);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)
        ->patch('/settings/locale', ['locale' => 'zh'])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBeNull();
});

test('every supported locale ships a parseable JSON dictionary', function () {
    $supported = app(LocaleResolver::class)->supported();
    expect($supported)->toContain('en');

    foreach ($supported as $locale) {
        $path = base_path("lang/{$locale}.json");
        expect(is_file($path))->toBeTrue("Missing lang/{$locale}.json");

        $raw = file_get_contents($path);
        $dict = json_decode($raw, true);
        expect($dict)->toBeArray("lang/{$locale}.json is not valid JSON");
        // Catch keys whose translation is the literal placeholder
        // string `null` / empty string — those would render blank in
        // the UI. Accept English-source values: those just fall back
        // through Laravel's JSON-key behaviour.
        foreach ($dict as $k => $v) {
            expect(is_string($v) && $v !== '')
                ->toBeTrue("lang/{$locale}.json key {$k} has empty/non-string value");
        }
    }
});

test('locale JSON dictionaries do not introduce keys absent from the English source', function () {
    // English IS the source of truth — translations should mirror its
    // keys, never invent new ones. Catches typos like "Save chnges"
    // creeping into a translated file.
    $en = json_decode(file_get_contents(base_path('lang/en.json')), true);
    expect($en)->toBeArray();

    foreach (app(LocaleResolver::class)->supported() as $locale) {
        if ($locale === 'en') {
            continue;
        }
        $dict = json_decode(file_get_contents(base_path("lang/{$locale}.json")), true);
        $extra = array_diff_key($dict, $en);
        expect($extra)->toBeEmpty(
            "lang/{$locale}.json has keys not in en.json: "
            .implode(', ', array_slice(array_keys($extra), 0, 5))
        );
    }
});
