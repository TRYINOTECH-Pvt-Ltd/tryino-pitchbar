<?php

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\User;

test('CreateWorkspaceForUser attaches the is_default_for_signup plan when one is flagged', function () {
    Plan::query()->update(['is_default_for_signup' => false]);
    $custom = Plan::factory()->create([
        'name' => 'Starter',
        'slug' => 'starter-default',
        'is_active' => true,
        'is_default_for_signup' => true,
        'price_cents' => 1900,
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceForUser::class)->handle($user);

    expect($workspace->plan_id)->toBe($custom->id);
});

test('falls back to a free-slug plan when no default flagged', function () {
    Plan::query()->update(['is_default_for_signup' => false]);
    $free = Plan::factory()->create([
        'name' => 'Free',
        'slug' => 'free',
        'is_active' => true,
        'price_cents' => 0,
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceForUser::class)->handle($user);

    expect($workspace->plan_id)->toBe($free->id);
});

test('PlanController update demotes any other default when this plan is set as default', function () {
    Plan::query()->update(['is_default_for_signup' => false]);
    $existingDefault = Plan::factory()->create([
        'name' => 'Old default',
        'slug' => 'old-def-'.uniqid(),
        'is_active' => true,
        'is_default_for_signup' => true,
        'price_cents' => 0,
        'monthly_conversations' => 100,
    ]);
    $target = Plan::factory()->create([
        'name' => 'New default',
        'slug' => 'new-def-'.uniqid(),
        'is_active' => true,
        'is_default_for_signup' => false,
        'price_cents' => 2900,
        'monthly_conversations' => 500,
    ]);

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch("/admin/plans/{$target->id}", [
            'name' => 'New default',
            'monthly_conversations' => 500,
            'monthly_messages' => 0,
            'max_tokens_per_response' => 2500,
            'price_cents' => 2900,
            'is_active' => true,
            'is_default_for_signup' => true,
        ])
        ->assertRedirect();

    expect($target->fresh()->is_default_for_signup)->toBeTrue();
    expect($existingDefault->fresh()->is_default_for_signup)->toBeFalse();
});
