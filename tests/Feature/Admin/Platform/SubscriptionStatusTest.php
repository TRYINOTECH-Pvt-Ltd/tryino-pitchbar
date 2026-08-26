<?php

use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

test('admin /admin/subscriptions reports truthful status for non-Stripe attached workspaces', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $pro = Plan::factory()->create([
        'name' => 'Pro',
        'slug' => 'pro-'.uniqid(),
        'price_cents' => 24900,
        'is_active' => true,
    ]);
    $free = Plan::factory()->create([
        'name' => 'Free',
        'slug' => 'free-'.uniqid(),
        'price_cents' => 0,
        'is_active' => true,
    ]);

    $manualPro = Workspace::factory()->create(['plan_id' => $pro->id]);
    $freeWs = Workspace::factory()->create(['plan_id' => $free->id]);
    $noPlanWs = Workspace::factory()->create(['plan_id' => null]);
    $own = Workspace::factory()->create(['owner_user_id' => $admin->id, 'plan_id' => $pro->id]);
    $admin->forceFill(['default_workspace_id' => $own->id])->save();

    $this->actingAs($admin)
        ->get('/admin/subscriptions')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('workspaces', function ($rows) use ($manualPro, $freeWs) {
                $byId = collect($rows)->keyBy('workspace_id');

                return $byId[$manualPro->id]['stripe_status'] === 'manual'
                    && $byId[$freeWs->id]['stripe_status'] === 'free'
                    // no-plan workspaces drop out of the query (whereNotNull
                    // plan_id), but if one slips in via Cashier sub it'd
                    // show as 'no_plan'. Verify it doesn't say 'no_subscription'
                    // for our paid workspaces.
                    && ! in_array('no_subscription', collect($rows)->pluck('stripe_status')->all(), true);
            })
        );
});
