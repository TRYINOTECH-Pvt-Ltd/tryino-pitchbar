<?php

use App\Services\I18n\LocaleCatalog;

test('admin Inertia root sets dir="rtl" for RTL locales', function (string $locale) {
    $response = $this->withHeaders(['Accept-Language' => $locale])
        ->get('/login?locale='.$locale);

    $response->assertOk();
    expect($response->getContent())->toContain('dir="rtl"');
})->with(['ar', 'fa', 'he', 'ur', 'ps']);

test('admin Inertia root sets dir="ltr" for LTR locales', function (string $locale) {
    $response = $this->withHeaders(['Accept-Language' => $locale])
        ->get('/login?locale='.$locale);

    $response->assertOk();
    expect($response->getContent())->toContain('dir="ltr"');
})->with(['en', 'es', 'fr', 'tr', 'de', 'ja', 'hi']);

test('marketing layout sets dir="rtl" for RTL locales', function (string $locale) {
    $response = $this->withHeaders(['Accept-Language' => $locale])
        ->get('/?locale='.$locale);

    $response->assertOk();
    expect($response->getContent())->toContain('dir="rtl"');
})->with(['ar', 'fa', 'he', 'ur']);

test('lead-captured email renders dir="rtl" for RTL locales', function () {
    app()->setLocale('ar');
    $html = view('emails.leads.captured', [
        'lead' => (object) ['email' => 'visitor@example.com'],
        'agentName' => 'Pitchbar Demo',
        'rows' => [],
        'inboxUrl' => 'https://example.com/inbox',
        'workspaceName' => 'Demo Workspace',
        'brandName' => 'Pitchbar',
    ])->render();

    expect($html)->toContain('dir="rtl"');
});

test('catalog flags every major RTL language as RTL', function () {
    foreach (['ar', 'fa', 'he', 'ur', 'ps', 'sd', 'dv', 'yi'] as $rtl) {
        $entry = LocaleCatalog::entryFor($rtl);
        expect($entry['rtl'])->toBeTrue("Locale {$rtl} should be RTL");
    }
});

test('catalog flags major LTR languages as LTR', function () {
    foreach (['en', 'es', 'fr', 'de', 'ja', 'hi', 'bn', 'ru', 'zh-Hans'] as $ltr) {
        $entry = LocaleCatalog::entryFor($ltr);
        expect($entry['rtl'])->toBeFalse("Locale {$ltr} should not be RTL");
    }
});
