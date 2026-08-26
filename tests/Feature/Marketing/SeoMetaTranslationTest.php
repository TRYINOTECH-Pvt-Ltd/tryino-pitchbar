<?php

use App\Enums\PlatformRole;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\I18n\TranslationOverrides;
use App\Support\SeoMeta;

/**
 * The marketing SEO meta (per-route page title + description) must be
 * translatable through the same override layer as the rest of the app.
 * SeoMeta interpolates hardcoded templates, so without surfacing them in
 * the manager they'd render in English even on a translated locale.
 */
beforeEach(function () {
    TranslationOverride::query()->delete();
    app(TranslationOverrides::class)->bump();
    $this->withoutVite();
});

function homeTitleTemplate(): string
{
    return collect(SeoMeta::translatableStrings())
        ->first(fn ($s) => str_contains($s, 'AI sales assistant for high-intent pages'));
}

test('the SEO meta collector returns title/description templates and skips pure placeholders', function () {
    $strings = SeoMeta::translatableStrings();

    expect(collect($strings)->contains(fn ($s) => str_contains($s, 'AI sales assistant for high-intent pages')))->toBeTrue()
        ->and(collect($strings)->contains(fn ($s) => str_contains($s, 'Pricing')))->toBeTrue();

    // A template that is nothing but a placeholder carries no copy.
    expect(collect($strings)->contains('{page_summary}'))->toBeFalse();
});

test('an overridden SEO title renders translated for the active locale, with {brand} interpolated', function () {
    $template = homeTitleTemplate();
    expect($template)->not->toBeNull();

    app(TranslationOverrides::class)->put('nl', $template, '{brand} - verkoop-AI voor pagina-intentie');

    app()->setLocale('nl');
    $seo = SeoMeta::for('home');

    expect($seo['title'])->toContain('verkoop-AI voor pagina-intentie')
        ->and($seo['title'])->not->toContain('{brand}'); // token interpolated after translation
});

test('a SEO meta template can be overridden via the translation manager', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $template = homeTitleTemplate();

    $this->actingAs($admin)
        ->put('/admin/translations/nl', ['key' => $template, 'value' => '{brand} - nl titel'])
        ->assertRedirect();

    expect(TranslationOverride::query()->where('locale', 'nl')->where('key', $template)->value('value'))
        ->toBe('{brand} - nl titel');
});

test('the manager still rejects a key that is neither a lang key, marketing copy, nor SEO meta', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->put('/admin/translations/nl', ['key' => 'totally not a real meta string anywhere', 'value' => 'x'])
        ->assertStatus(422);
});
