<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Providers\AppSettingsOverrideServiceProvider;

/**
 * Buyer-reported (2026-05-21): Cloudflare + LLM + Vector provider knobs
 * must come from the database, not `.env`. AppSettingsOverrideService
 * Provider already hydrates config() from app_settings at boot; this
 * suite pins the contract end-to-end so consumers never reach for
 * env() at runtime.
 */
test('super_admin can save Cloudflare credentials including AI Gateway + Browser Rendering', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/system/cloudflare', [
            'cloudflare_account_id' => 'acc_e2e_test',
            'cloudflare_api_token' => 'cfat_e2e_test_token',
            'cloudflare_chat_model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'cloudflare_embed_model' => '@cf/baai/bge-base-en-v1.5',
            'cloudflare_vectorize_index' => 'pitchbar-chunks',
            'cloudflare_ai_gateway_url' => 'https://gateway.ai.cloudflare.com/v1/acc/gw',
            'cloudflare_browser_rendering' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = AppSetting::singleton()->fresh();
    expect($settings->cloudflare_account_id)->toBe('acc_e2e_test');
    expect($settings->cloudflare_api_token)->toBe('cfat_e2e_test_token');
    expect($settings->cloudflare_ai_gateway_url)
        ->toBe('https://gateway.ai.cloudflare.com/v1/acc/gw');
    expect($settings->cloudflare_browser_rendering)->toBeTrue();
});

test('super_admin can disable Cloudflare Browser Rendering via settings', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/system/cloudflare', [
            'cloudflare_account_id' => 'acc_e2e_test',
            'cloudflare_browser_rendering' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AppSetting::singleton()->fresh()->cloudflare_browser_rendering)
        ->toBeFalse();
});

test('AppSetting Cloudflare values override config at boot', function () {
    AppSetting::singleton()->forceFill([
        'cloudflare_account_id' => 'db_account_wins',
        'cloudflare_api_token' => 'db_token_wins',
        'cloudflare_chat_model' => '@cf/db-chat',
        'cloudflare_ai_gateway_url' => 'https://gw.db.example/v1',
        'cloudflare_browser_rendering' => false,
        'llm_provider' => 'cloudflare',
        'vector_provider' => 'cloudflare',
    ])->save();
    AppSetting::flushSingleton();

    // Re-trigger boot of the override provider by re-running boot().
    (new AppSettingsOverrideServiceProvider(app()))->boot();

    expect(config('services.cloudflare.account_id'))->toBe('db_account_wins');
    expect(config('services.cloudflare.api_token'))->toBe('db_token_wins');
    expect(config('services.cloudflare.chat_model'))->toBe('@cf/db-chat');
    expect(config('services.cloudflare.ai_gateway_url'))
        ->toBe('https://gw.db.example/v1');
    expect(config('services.cloudflare.browser_rendering'))->toBeFalse();
    expect(config('services.llm.provider'))->toBe('cloudflare');
    expect(config('services.vector.provider'))->toBe('cloudflare');
});

test('DB-only Cloudflare credentials populate config without any .env presence', function () {
    // Production install — .env wiped, DB carries the credentials.
    // Pre-fix the resolver fell back to env() and saw empty values,
    // returning FakeOpenAi. Now config() is populated from the DB at
    // boot, so downstream resolvers see real values.
    config()->set('services.cloudflare.account_id', '');
    config()->set('services.cloudflare.api_token', '');
    config()->set('services.llm.provider', '');

    AppSetting::singleton()->forceFill([
        'cloudflare_account_id' => 'db_only_account',
        'cloudflare_api_token' => 'db_only_token',
        'llm_provider' => 'cloudflare',
    ])->save();
    AppSetting::flushSingleton();

    (new AppSettingsOverrideServiceProvider(app()))->boot();

    expect(config('services.cloudflare.account_id'))->toBe('db_only_account');
    expect(config('services.cloudflare.api_token'))->toBe('db_only_token');
    expect(config('services.llm.provider'))->toBe('cloudflare');
});

test('Crawler tier picks Cloudflare Browser Rendering only when DB toggle is on', function () {
    // DB ON: tier includes cloudflare_browser
    AppSetting::singleton()->forceFill([
        'cloudflare_account_id' => 'tier_test_account',
        'cloudflare_api_token' => 'tier_test_token',
        'cloudflare_browser_rendering' => true,
    ])->save();
    AppSetting::flushSingleton();
    (new AppSettingsOverrideServiceProvider(app()))->boot();
    expect(config('services.cloudflare.browser_rendering'))->toBeTrue();

    // DB OFF: config drops back to false.
    AppSetting::singleton()->forceFill([
        'cloudflare_browser_rendering' => false,
    ])->save();
    AppSetting::flushSingleton();
    (new AppSettingsOverrideServiceProvider(app()))->boot();
    expect(config('services.cloudflare.browser_rendering'))->toBeFalse();
});

test('formValues exposes ai_gateway_url + browser_rendering to the admin UI', function () {
    AppSetting::singleton()->forceFill([
        'cloudflare_ai_gateway_url' => 'https://gw.example/v1/x/y',
        'cloudflare_browser_rendering' => false,
    ])->save();

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->get('/settings/system')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('form.cloudflare_ai_gateway_url', 'https://gw.example/v1/x/y')
            ->where('form.cloudflare_browser_rendering', false));
});
