<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;

/**
 * Buyer-reported (Jamiu, 2026-05-20): saving the "Integrations
 * available to workspaces" form on /settings/system?section=integrations
 * 404'd with "Something went wrong. Please try again." The bug was a
 * missing section in BOTH the route regex AND the validationRulesFor()
 * match. Same root cause hit the pricing matrix tab; this file covers
 * both surfaces so a future drop doesn't silently 404 again.
 */
test('super_admin can save the Integrations toggles via /settings/system/integrations', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/system/integrations', [
            'integrations_enabled' => [
                'slack' => true,
                'notion' => true,
                'google' => true,
                'webhooks' => true,
                'wordpress' => true,
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = AppSetting::singleton()->fresh();
    expect($settings->integrations_enabled['slack'])->toBeTrue();
    expect($settings->integrations_enabled['wordpress'])->toBeTrue();
});

test('super_admin can disable a kind via the Integrations form', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/system/integrations', [
            'integrations_enabled' => [
                'slack' => false,
                'notion' => true,
                'google' => true,
                'webhooks' => true,
                'wordpress' => true,
            ],
        ])
        ->assertRedirect();

    expect(AppSetting::singleton()->fresh()->integrations_enabled['slack'])->toBeFalse();
});

test('super_admin can save the Pricing FAQ via /settings/system/pricing and it renders on /pricing', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    // Submit using the SAME {q,a} key shape the admin React form sends
    // (resources/js/pages/settings/system.tsx). Pre-fix the validator
    // expected {question,answer} and silently stripped the q/a payload
    // — saves landed but stored empty rows, so /pricing fell back to
    // the hard-coded defaults. Client report 2026-05-22.
    $this->actingAs($admin)
        ->patch('/settings/system/pricing', [
            'pricing_faqs' => [
                ['q' => 'My custom Q1?', 'a' => 'My custom A1.'],
                ['q' => 'My custom Q2?', 'a' => 'My custom A2.'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = AppSetting::singleton()->fresh();
    expect($settings->pricing_faqs)->toHaveCount(2);
    expect($settings->pricing_faqs[0])->toMatchArray(['q' => 'My custom Q1?', 'a' => 'My custom A1.']);

    // End-to-end: the persisted rows must show up on the public
    // /pricing surface (Inertia prop `faqs`).
    AppSetting::flushSingleton();
    $response = $this->get('/pricing')->assertOk();
    $payload = $response->viewData('page');
    $faqs = $payload['props']['faqs'] ?? null;

    expect($faqs)->toBeArray()->toHaveCount(2);
    expect($faqs[0])->toMatchArray(['q' => 'My custom Q1?', 'a' => 'My custom A1.']);
});

test('pricing FAQ validator rejects the legacy {question,answer} shape so the admin sees an error instead of silently saving empties', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/system/pricing', [
            'pricing_faqs' => [
                ['question' => 'Legacy Q', 'answer' => 'Legacy A'],
            ],
        ])
        ->assertSessionHasErrors(['pricing_faqs.0.q', 'pricing_faqs.0.a']);
});

test('legacy persisted {question,answer} rows still render on /pricing via the read-side normaliser', function () {
    AppSetting::singleton()->forceFill([
        'pricing_faqs' => [
            ['question' => 'Legacy Q', 'answer' => 'Legacy A'],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $response = $this->get('/pricing')->assertOk();
    $faqs = $response->viewData('page')['props']['faqs'] ?? null;

    expect($faqs)->toBeArray()->toHaveCount(1);
    expect($faqs[0])->toMatchArray(['q' => 'Legacy Q', 'a' => 'Legacy A']);
});

test('unknown sections are rejected (regression guard)', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    // 404 (regex constraint miss) or 405 (Symfony method-mismatch
    // fall-through when other HTTP methods serve the literal path).
    // Both prove the operator cannot patch an arbitrary section.
    $status = $this->actingAs($admin)
        ->patch('/settings/system/totally_fake_section', [])
        ->getStatusCode();

    expect($status)->toBeIn([404, 405]);
});
