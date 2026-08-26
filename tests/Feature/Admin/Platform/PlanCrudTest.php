<?php

use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripeProductSync;

beforeEach(function () {
    Plan::query()->delete();
});

function platformAdminForPlans(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

test('admin/plans index renders all plans for super_admin', function () {
    $admin = platformAdminForPlans();
    Plan::create([
        'name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100,
        'price_cents' => 0, 'is_active' => true,
    ]);
    Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->get('/admin/plans');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->component('admin/plans/index')
        ->has('plans', 2));
});

test('a customer cannot reach /admin/plans (404 to hide existence)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)->get('/admin/plans')->assertStatus(404);
});

test('create plan triggers Stripe sync for paid plans', function () {
    $admin = platformAdminForPlans();
    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldReceive('syncPlan')->once()
        ->with(Mockery::on(fn ($p) => $p->price_cents === 4900 && $p->name === 'Standard'));
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->post('/admin/plans', [
            'name' => 'Standard',
            'monthly_conversations' => 500,
            'price_cents' => 4900,
            'is_active' => true,
            'features' => ['remove_branding' => true],
        ])
        ->assertRedirect('/admin/plans');

    expect(Plan::query()->where('slug', 'standard')->exists())->toBeTrue();
});

test('create plan with price 0 skips Stripe sync', function () {
    $admin = platformAdminForPlans();
    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldNotReceive('syncPlan');
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->post('/admin/plans', [
            'name' => 'Free',
            'monthly_conversations' => 100,
            'price_cents' => 0,
            'is_active' => true,
        ])
        ->assertRedirect('/admin/plans');

    expect(Plan::query()->where('slug', 'free')->exists())->toBeTrue();
});

test('slug is auto-generated and uniquified on collision', function () {
    $admin = platformAdminForPlans();
    Plan::create([
        'name' => 'Standard', 'slug' => 'standard',
        'monthly_conversations' => 500, 'price_cents' => 4900,
        'is_active' => true,
    ]);
    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldReceive('syncPlan')->once();
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->post('/admin/plans', [
            'name' => 'Standard',
            'monthly_conversations' => 500,
            'price_cents' => 4900,
            'is_active' => true,
        ])
        ->assertRedirect('/admin/plans');

    expect(Plan::query()->pluck('slug')->all())->toContain('standard', 'standard-2');
});

test('update plan persists fields and re-runs Stripe sync', function () {
    $admin = platformAdminForPlans();
    $plan = Plan::create([
        'name' => 'Standard', 'slug' => 'standard',
        'monthly_conversations' => 500, 'price_cents' => 4900,
        'is_active' => true,
    ]);
    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldReceive('syncPlan')->once()
        ->with(Mockery::on(fn ($p) => $p->price_cents === 5900));
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->patch("/admin/plans/{$plan->id}", [
            'name' => 'Standard',
            'monthly_conversations' => 500,
            'price_cents' => 5900,
            'is_active' => true,
        ])
        ->assertRedirect('/admin/plans');

    expect($plan->fresh()->price_cents)->toBe(5900);
});

test('destroy plan soft-deletes (is_active=false) and archives on Stripe', function () {
    $admin = platformAdminForPlans();
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);
    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldReceive('archivePlan')->once();
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->delete("/admin/plans/{$plan->id}")
        ->assertRedirect('/admin/plans');

    $fresh = $plan->fresh();
    expect($fresh)->not->toBeNull();
    expect((bool) $fresh->is_active)->toBeFalse();
});

test('validation rejects negative price', function () {
    $admin = platformAdminForPlans();
    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldNotReceive('syncPlan');
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->post('/admin/plans', [
            'name' => 'Bad',
            'monthly_conversations' => 100,
            'price_cents' => -5,
        ])
        ->assertSessionHasErrors('price_cents');
});

test('sync endpoint runs StripeProductSync and returns the fresh stripe IDs', function () {
    $admin = platformAdminForPlans();
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);

    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldReceive('syncPlan')->once()
        ->with(Mockery::on(fn ($p) => $p->id === $plan->id))
        ->andReturnUsing(function ($p) {
            // Mimic syncPlan persisting the product + price ids.
            $p->forceFill([
                'stripe_product_id' => 'prod_test_123',
                'stripe_price_id' => 'price_test_456',
            ])->save();
        });
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->postJson("/admin/plans/{$plan->id}/sync")
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('stripe_product_id', 'prod_test_123')
        ->assertJsonPath('stripe_price_id', 'price_test_456');
});

test('sync endpoint reports Stripe failures without 500ing', function () {
    $admin = platformAdminForPlans();
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);

    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldReceive('syncPlan')->once()
        ->andThrow(new RuntimeException('Stripe key invalid'));
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->postJson("/admin/plans/{$plan->id}/sync")
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Stripe key invalid');
});

test('sync endpoint short-circuits for free plans without calling Stripe', function () {
    $admin = platformAdminForPlans();
    $plan = Plan::create([
        'name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100,
        'price_cents' => 0, 'is_active' => true,
    ]);

    $sync = Mockery::mock(StripeProductSync::class);
    $sync->shouldNotReceive('syncPlan');
    $this->app->instance(StripeProductSync::class, $sync);

    $this->actingAs($admin)
        ->postJson("/admin/plans/{$plan->id}/sync")
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn ($m) => is_string($m)
            && str_contains((string) $m, 'no Stripe sync needed'));
});

test('non-admin cannot reach the sync endpoint', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);

    $this->actingAs($customer)
        ->postJson("/admin/plans/{$plan->id}/sync")
        ->assertStatus(404);
});

test('non-admin cannot reach the create / store / update / destroy endpoints', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);

    $this->actingAs($customer)->get('/admin/plans/create')->assertStatus(404);
    $this->actingAs($customer)->post('/admin/plans', [])->assertStatus(404);
    $this->actingAs($customer)->patch("/admin/plans/{$plan->id}", [])->assertStatus(404);
    $this->actingAs($customer)->delete("/admin/plans/{$plan->id}")->assertStatus(404);

    expect((bool) $plan->fresh()->is_active)->toBeTrue();
});
