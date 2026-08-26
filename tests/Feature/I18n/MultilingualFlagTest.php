<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\I18n\LocaleResolver;
use Illuminate\Http\Request;

/**
 * MULTILINGUAL_ENABLED master switch. The suite runs with it ON (phpunit
 * env), so these tests flip config() OFF explicitly to pin the English-only
 * default behavior, and ON to confirm the language layer still works.
 */
function mlUser(string $locale = 'en'): User
{
    $user = User::factory()->create(['locale' => $locale]);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    return $user;
}

// ── OFF (the default): English-only ─────────────────────────────────

test('with the flag off, ?locale and the user locale are ignored — always English', function () {
    config(['app.multilingual_enabled' => false]);
    $user = mlUser('fr');

    $this->actingAs($user)->get('/dashboard?locale=es')
        ->assertInertia(fn ($p) => $p->where('i18n.locale', 'en'));
});

test('with the flag off, the shared i18n.multilingual prop is false', function () {
    config(['app.multilingual_enabled' => false]);
    $user = mlUser();

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('i18n.multilingual', false));
});

test('with the flag off, the Translation Manager 404s even for a super-admin', function () {
    config(['app.multilingual_enabled' => false]);
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)->get('/admin/translations')->assertNotFound();
    $this->actingAs($admin)->get('/admin/translations/nl')->assertNotFound();
});

test('with the flag off, the geo locale suggestion is suppressed', function () {
    config(['app.multilingual_enabled' => false]);
    $request = Request::create('/', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'ES']);

    expect(app(LocaleResolver::class)->suggestionFor($request, 'en'))->toBeNull();
});

// ── ON: the language layer works ────────────────────────────────────

test('with the flag on, ?locale switches the resolved locale', function () {
    config(['app.multilingual_enabled' => true]);
    $user = mlUser('en');

    $this->actingAs($user)->get('/dashboard?locale=fr')
        ->assertInertia(fn ($p) => $p->where('i18n.locale', 'fr'));
});

test('with the flag on, i18n.multilingual is true and the Manager is reachable', function () {
    config(['app.multilingual_enabled' => true]);
    $this->withoutVite();

    // Shared prop: check on a normal user's dashboard (a super-admin's
    // /dashboard redirects to /admin, which isn't an Inertia response).
    $this->actingAs(mlUser())->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('i18n.multilingual', true));

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $this->actingAs($admin)->get('/admin/translations')->assertOk();
});

test('with the flag on, a geo suggestion is offered for a mapped country', function () {
    config(['app.multilingual_enabled' => true]);
    $request = Request::create('/', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'ES']);

    expect(app(LocaleResolver::class)->suggestionFor($request, 'en'))
        ->not->toBeNull();
});
