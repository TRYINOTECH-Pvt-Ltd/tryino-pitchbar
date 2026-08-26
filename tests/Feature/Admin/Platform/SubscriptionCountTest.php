<?php

use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    Plan::query()->delete();
});

function makePlatformAdmin(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

test('admin subscriptions page counts subscribers by workspaces.plan_id, not Stripe subs', function () {
    $admin = makePlatformAdmin();

    $free = Plan::create([
        'name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100,
        'price_cents' => 0, 'is_active' => true,
    ]);
    $standard = Plan::create([
        'name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500,
        'price_cents' => 4900, 'is_active' => true,
    ]);
    $pro = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);

    Workspace::factory()->count(3)->create(['plan_id' => $free->id]);
    Workspace::factory()->count(2)->create(['plan_id' => $standard->id]);
    Workspace::factory()->count(1)->create(['plan_id' => $pro->id]);
    Workspace::factory()->count(4)->create(['plan_id' => null]);

    $response = $this->actingAs($admin)->get('/admin/subscriptions');
    $response->assertOk();

    $plans = collect($response->viewData('page')['props']['plans']);

    expect($plans->firstWhere('slug', 'free')['subscriber_count'])->toBe(3);
    expect($plans->firstWhere('slug', 'standard')['subscriber_count'])->toBe(2);
    expect($plans->firstWhere('slug', 'pro')['subscriber_count'])->toBe(1);
});

test('subscriber count reads zero when no workspaces have that plan_id set', function () {
    $admin = makePlatformAdmin();

    Plan::create([
        'name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500,
        'price_cents' => 4900, 'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->get('/admin/subscriptions');
    $response->assertOk();

    $plans = collect($response->viewData('page')['props']['plans']);
    expect($plans->firstWhere('slug', 'standard')['subscriber_count'])->toBe(0);
});

test('soft-deleted workspaces are excluded from subscriber counts', function () {
    $admin = makePlatformAdmin();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000,
        'price_cents' => 24900, 'is_active' => true,
    ]);

    Workspace::factory()->count(2)->create(['plan_id' => $plan->id]);
    $deleted = Workspace::factory()->create(['plan_id' => $plan->id]);
    $deleted->delete();

    $response = $this->actingAs($admin)->get('/admin/subscriptions');
    $response->assertOk();

    $plans = collect($response->viewData('page')['props']['plans']);
    expect($plans->firstWhere('slug', 'pro')['subscriber_count'])->toBe(2);
});
