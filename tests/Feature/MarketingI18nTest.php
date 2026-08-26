<?php

use App\Services\I18n\LocaleResolver;

test('marketing routes render in core curated locales', function (string $route) {
    // Smoke-test against the four curated locales — we ship translation
    // dictionaries with full coverage for these. Auto-discovered
    // locales (the 130+ language tail) only cover UI chrome; their
    // marketing content falls back to English source — that's covered
    // by the `<html lang>` test below, which iterates the full set.
    foreach (['en', 'es', 'fr', 'tr'] as $locale) {
        $response = $this->withHeaders(['Accept-Language' => $locale])
            ->get($route.'?locale='.$locale);

        $response->assertOk();
        expect($response->getContent())->toContain('<html lang="'.$locale.'"');
    }
})->with(['/', '/pricing', '/how-it-works', '/privacy', '/terms']);

test('marketing home renders successfully for every auto-discovered locale', function () {
    // Spot-check a single route against the long-tail locales — make
    // sure none of them break the renderer (RTL toggle, missing keys,
    // weird code in the URL).
    foreach (app(LocaleResolver::class)->supported() as $locale) {
        $response = $this->get('/?locale='.$locale);
        $response->assertOk();
        expect($response->getContent())->toContain('<html lang="'.$locale.'"');
    }
});

test('lead-captured email renders in the configured locale', function () {
    app()->setLocale('es');
    $html = view('emails.leads.captured', [
        'lead' => (object) ['email' => 'visitor@example.com'],
        'agentName' => 'Pitchbar Demo',
        'rows' => [],
        'inboxUrl' => 'https://example.com/inbox',
        'workspaceName' => 'Demo Workspace',
        'brandName' => 'Pitchbar',
    ])->render();

    expect($html)
        ->toContain('Nuevo contacto capturado')
        ->toContain('Abrir en bandeja');
});
