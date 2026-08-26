<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Billing\StripeProductSync;

function ownerWithWorkspace(string $planSlug = 'free'): array
{
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }

    $plan = Plan::query()->where('slug', $planSlug)->first();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan?->id,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('checkout returns a friendly error when STRIPE_SECRET is missing', function () {
    config()->set('cashier.secret', '');
    putenv('STRIPE_SECRET=');

    ['user' => $user] = ownerWithWorkspace();

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/checkout', ['plan_slug' => 'standard'])
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});

test('checkout refuses a free plan with a friendly error', function () {
    config()->set('cashier.secret', 'sk_test_dummy');

    ['user' => $user] = ownerWithWorkspace();

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/checkout', ['plan_slug' => 'free'])
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});

test('non-owner cannot start checkout', function () {
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'editor',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)
        ->post('/billing/checkout', ['plan_slug' => 'standard'])
        ->assertForbidden();
});

test('checkout surfaces a friendly error if StripeProductSync fails', function () {
    config()->set('cashier.secret', 'sk_test_dummy');

    ['user' => $user] = ownerWithWorkspace();

    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldReceive('ensurePriceFor')
        ->once()
        ->andThrow(new RuntimeException('stripe is angry'));
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/checkout', ['plan_slug' => 'standard'])
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});
