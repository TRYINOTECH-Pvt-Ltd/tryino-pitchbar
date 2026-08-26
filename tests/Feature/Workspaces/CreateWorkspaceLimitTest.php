<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

test('user with workspaces_limit null can create unlimited workspaces', function () {
    $plan = Plan::create([
        'name' => 'Unlim',
        'slug' => 'unlim-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 1000,
        'price_cents' => 0,
        'workspaces_limit' => null,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Workspace::factory()->count(3)->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
    ]);

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'Another One'])
        ->assertRedirect(route('dashboard'));

    expect(Workspace::query()->where('owner_user_id', $user->id)->count())->toBe(4);
});

test('user blocked when workspaces_limit reached', function () {
    $plan = Plan::create([
        'name' => 'Capped',
        'slug' => 'capped-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 1000,
        'price_cents' => 0,
        'workspaces_limit' => 2,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Workspace::factory()->count(2)->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
    ]);

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'Third'])
        ->assertSessionHasErrors('name');

    expect(Workspace::query()->where('owner_user_id', $user->id)->count())->toBe(2);
});

test('user with zero workspaces is always allowed to create the first one', function () {
    $plan = Plan::create([
        'name' => 'ZeroCap',
        'slug' => 'zero-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 1000,
        'price_cents' => 0,
        'workspaces_limit' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'First'])
        ->assertRedirect(route('dashboard'));

    expect(Workspace::query()->where('owner_user_id', $user->id)->count())->toBe(1);
});

test('unauthenticated request is rejected', function () {
    $this->post(route('workspaces.store'), ['name' => 'X'])
        ->assertRedirect();
});

test('validates name presence + length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'a'])
        ->assertSessionHasErrors('name');

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => str_repeat('z', 90)])
        ->assertSessionHasErrors('name');
});
