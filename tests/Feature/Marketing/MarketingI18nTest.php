<?php

use App\Models\AppSetting;

test('marketing home renders Dutch copy when the visitor locale is nl', function () {
    $this->withUnencryptedCookie('pb_locale', 'nl')
        ->get('/')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('content.hero.line_one', 'Maak van elke')
                ->where('content.hero.badge', "AI-verkoopassistent voor pagina's met hoge koopintentie"),
        );
});

test('marketing home stays English for the default locale', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->where('content.hero.line_one', 'Turn every'),
        );
});

test('operator-customised copy passes through untranslated', function () {
    $settings = AppSetting::singleton();
    $settings->forceFill([
        'marketing_home_content' => [
            'hero' => ['line_one' => 'Onze eigen aanhef'],
        ],
    ])->save();

    $this->withUnencryptedCookie('pb_locale', 'nl')
        ->get('/')
        ->assertOk()
        ->assertInertia(
            // Custom string has no dictionary entry — verbatim, never mangled.
            fn ($page) => $page->where('content.hero.line_one', 'Onze eigen aanhef'),
        );
});

test('shell content (nav and footer) is translated too', function () {
    $this->withUnencryptedCookie('pb_locale', 'nl')
        ->get('/pricing')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->where('shell.nav_items.2.label', 'Hoe het werkt'),
        );
});
