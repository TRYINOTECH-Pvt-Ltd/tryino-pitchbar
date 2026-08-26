<?php

use App\Enums\PlatformRole;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\I18n\TranslationOverrides;
use App\Support\MarketingCopy;
use App\Support\MarketingHomeContent;
use App\Support\MarketingTranslator;

/**
 * Marketing copy (hero / pricing / FAQ + operator edits) must be
 * translatable through the same override layer as the rest of the app —
 * before this, strings that weren't shipped lang keys (e.g. the hero accent
 * "high-intent") fell back to English, producing the mixed-language hero a
 * buyer reported.
 */
beforeEach(function () {
    TranslationOverride::query()->delete();
    app(TranslationOverrides::class)->bump();
    $this->withoutVite();
});

test('the collector returns human copy and skips urls / icons / prices', function () {
    $strings = MarketingCopy::strings();

    // Real copy that previously had no dictionary key.
    expect($strings)->toContain('high-intent')
        ->toContain('sales')
        ->toContain('conversation.');

    // Never translate structural / glyph leaves.
    expect($strings)->not->toContain('/pricing')
        ->not->toContain('__primary__')
        ->not->toContain('BarChart3')
        ->not->toContain('$199')
        ->not->toContain('53%');
});

test('a marketing copy string can be overridden via the translation manager', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    // "high-intent" is NOT a shipped en.json key — before the fix this 422'd.
    $this->actingAs($admin)
        ->put('/admin/translations/nl', ['key' => 'high-intent', 'value' => 'hoge-intentie'])
        ->assertRedirect();

    expect(TranslationOverride::query()->where('locale', 'nl')->where('key', 'high-intent')->value('value'))
        ->toBe('hoge-intentie');
});

test('an overridden marketing string renders translated in the marketing content', function () {
    app(TranslationOverrides::class)->put('nl', 'high-intent', 'hoge-intentie');
    app(TranslationOverrides::class)->put('nl', 'conversation.', 'conversatie.');

    app()->setLocale('nl');
    $translated = MarketingTranslator::translate(MarketingHomeContent::resolve());

    // These two had NO shipped dictionary entry before — only the override
    // makes them translate now.
    expect($translated['hero']['accent'])->toBe('hoge-intentie')
        ->and($translated['hero']['line_four_highlight'])->toBe('conversatie.');
});

test('the manager still rejects a key that is neither a lang key nor marketing copy', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->put('/admin/translations/nl', ['key' => 'totally not a real string anywhere', 'value' => 'x'])
        ->assertStatus(422);
});
