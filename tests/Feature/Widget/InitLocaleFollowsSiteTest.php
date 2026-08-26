<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Models\Workspace;
use App\Services\I18n\TranslationOverrides;

/**
 * The widget must follow the host page's language, not stay pinned to
 * the agent's default. The embed sends `locale` (its `data-locale`, else
 * the page's `<html lang>`); the server treats it as the top-priority
 * signal and localizes the launcher label + starter chips so the SaaS
 * preset's "Ask about the product" / "What does it cost?" render in the
 * site's language instead of English.
 */
beforeEach(function () {
    TranslationOverride::query()->delete();
    app(TranslationOverrides::class)->bump();
});

function saasDemoAgent(string $languageDefault = 'en'): Agent
{
    $workspace = Workspace::factory()->create();

    return Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'language_default' => $languageDefault,
        'site_type' => 'saas',
        'starter_prompts' => [
            'What does it cost?',
            'How is this different from competitors?',
            'Can I try it for free?',
        ],
        'theme' => ['launcher_label' => 'Ask about the product'],
    ]);
}

test('the page locale overrides the agent default for the widget UI', function () {
    $agent = saasDemoAgent('en');

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'locale' => 'nl',
        ]);

    $response->assertOk()->assertJsonPath('data.agent.locale', 'nl');
});

test('a regional <html lang> tag resolves to the base language', function () {
    $agent = saasDemoAgent('en');

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'locale' => 'nl-NL',
        ]);

    $response->assertOk()->assertJsonPath('data.agent.locale', 'nl');
});

test('starter prompts + launcher label are localized to the page locale', function () {
    $overrides = app(TranslationOverrides::class);
    $overrides->put('nl', 'What does it cost?', 'Wat kost het?');
    $overrides->put('nl', 'How is this different from competitors?', 'Waarin verschilt dit van concurrenten?');
    $overrides->put('nl', 'Can I try it for free?', 'Kan ik het gratis proberen?');
    $overrides->put('nl', 'Ask about the product', 'Vraag over het product');

    $agent = saasDemoAgent('en');

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'locale' => 'nl',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.agent.starter_prompts.0', 'Wat kost het?')
        ->assertJsonPath('data.agent.starter_prompts.2', 'Kan ik het gratis proberen?')
        ->assertJsonPath('data.agent.theme.launcher_label', 'Vraag over het product');
});

test('without a page locale the widget keeps the agent default language', function () {
    $agent = saasDemoAgent('en');

    // No `locale`, no Dutch override seeded — chrome stays English.
    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonPath('data.agent.locale', 'en')
        ->assertJsonPath('data.agent.starter_prompts.0', 'What does it cost?')
        ->assertJsonPath('data.agent.theme.launcher_label', 'Ask about the product');
});

test('shipped Dutch defaults localize the preset chrome with no override', function () {
    // No overrides seeded (beforeEach cleared them). The Dutch wording must
    // come from the shipped lang/nl.json baseline so blengi's demo works out
    // of the box on deploy.
    $agent = saasDemoAgent('en');

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'locale' => 'nl',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.agent.starter_prompts.0', 'Wat kost het?')
        ->assertJsonPath('data.agent.theme.launcher_label', 'Stel een vraag over het product');
});

test('a custom starter prompt with no override passes through verbatim', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'language_default' => 'en',
        'starter_prompts' => ['Hoeveel kost verzending?'],
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'locale' => 'nl',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.agent.starter_prompts.0', 'Hoeveel kost verzending?');
});

test('the translation manager accepts a vertical-chrome key', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->put('/admin/translations/nl', ['key' => 'Ask about the product', 'value' => 'Vraag over het product'])
        ->assertRedirect();

    expect(TranslationOverride::query()->where('locale', 'nl')->where('key', 'Ask about the product')->value('value'))
        ->toBe('Vraag over het product');
});
