<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\I18n\LocaleResolver;
use Illuminate\Http\Request;

test('Inertia shares localeSuggestion when CF-IPCountry maps to a translation', function () {
    $response = $this->withHeaders(['CF-IPCountry' => 'ES'])->get('/');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('localeSuggestion.suggested', 'es')
        ->where('localeSuggestion.current', 'en')
    );
});

test('localeSuggestion is null for unmapped countries', function () {
    $response = $this->withHeaders(['CF-IPCountry' => 'IN'])->get('/');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('localeSuggestion', null)
    );
});

test('localeSuggestion is null when CF-IPCountry is XX (unknown)', function () {
    $response = $this->withHeaders(['CF-IPCountry' => 'XX'])->get('/');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('localeSuggestion', null)
    );
});

test('localeSuggestion is null when dismiss cookie is set', function () {
    $response = $this->withHeaders(['CF-IPCountry' => 'ES'])
        ->withUnencryptedCookie(LocaleResolver::DISMISS_COOKIE, '1')
        ->get('/');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('localeSuggestion', null)
    );
});

test('localeSuggestion is null when current locale already matches the suggestion', function () {
    $response = $this->withHeaders(['CF-IPCountry' => 'ES'])
        ->get('/?locale=es');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('localeSuggestion', null)
    );
});

test('PATCH /locale/switch is open to unauth visitors', function () {
    $this->patch('/locale/switch', ['locale' => 'es'])
        ->assertRedirect();
});

test('PATCH /locale/switch persists users.locale when signed in', function () {
    $user = User::factory()->create(['locale' => null]);
    Workspace::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)
        ->patch('/locale/switch', ['locale' => 'fr'])
        ->assertRedirect();

    expect($user->fresh()->locale)->toBe('fr');
});

test('PATCH /locale/switch sets pb_locale cookie for unauth visitors', function () {
    $response = $this->patch('/locale/switch', ['locale' => 'tr']);

    $response->assertRedirect();
    $cookies = $response->headers->getCookies();
    $names = array_map(fn ($c) => $c->getName(), $cookies);
    expect($names)->toContain('pb_locale');
});

test('POST /locale/dismiss-suggestion sets the dismiss cookie', function () {
    $response = $this->post('/locale/dismiss-suggestion');

    $response->assertNoContent();
    $cookies = $response->headers->getCookies();
    $names = array_map(fn ($c) => $c->getName(), $cookies);
    expect($names)->toContain(LocaleResolver::DISMISS_COOKIE);
});

test('LocaleResolver respects pb_locale cookie when no user override', function () {
    $resolver = new LocaleResolver;
    $request = Request::create('/', 'GET');
    $request->cookies->set('pb_locale', 'tr');
    $request->headers->set('Accept-Language', 'fr');

    expect($resolver->forRequest($request))->toBe('tr');
});

test('LocaleResolver::suggestionFor maps every supported country', function () {
    $resolver = new LocaleResolver;
    foreach (LocaleResolver::COUNTRY_TO_LOCALE as $country => $expectedLocale) {
        $request = Request::create('/', 'GET');
        $request->headers->set('CF-IPCountry', $country);

        $suggestion = $resolver->suggestionFor($request, 'en');

        expect($suggestion)->toBe([
            'suggested' => $expectedLocale,
            'current' => 'en',
        ], "Country {$country} should suggest {$expectedLocale}");
    }
});
