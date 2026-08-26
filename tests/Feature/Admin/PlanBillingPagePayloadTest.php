<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Buyer reports 2026-05-24:
 *   1. Billing page showed "Unlimited data sources" for every plan
 *      regardless of the plan's actual `sources_limit` — controller
 *      only surfaced `monthly_conversations` + `price_cents` + the
 *      legacy `features` list, so the React page had no real per-plan
 *      numbers to render.
 *   2. Monthly/yearly toggle never worked because plans had no
 *      `yearly_price_cents` column.
 *   3. Plan cards needed a complete feature comparison (agents,
 *      sources, workflows, integrations, members, workspaces,
 *      API access, branding removal).
 *
 * This test guards every field the React page now consumes so a
 * future controller trim can't silently drop one and re-introduce the
 * "Unlimited data sources" stub.
 */
function billingOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return compact('user', 'workspace');
}

test('billing page exposes every per-plan limit + add-on flag to the React layer', function () {
    ['user' => $user] = billingOwner();
    $slug = 'test-payload-'.bin2hex(random_bytes(3));

    Plan::query()->create([
        'name' => 'Test Standard Payload',
        'slug' => $slug,
        'monthly_conversations' => 500,
        'monthly_messages' => 5000,
        'price_cents' => 4900,
        'yearly_price_cents' => 49000,
        'agents_limit' => 3,
        'sources_limit' => 50,
        'workflows_limit' => 10,
        'integrations_limit' => 5,
        'members_limit' => 5,
        'workspaces_limit' => 1,
        'api_access' => true,
        'features' => ['remove_branding' => true],
        'is_active' => true,
        'show_on_pricing_page' => true,
    ]);

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk()
        ->assertInertia(function ($p) use ($slug) {
            $plans = $p->toArray()['props']['plans'];
            $seeded = collect($plans)->firstWhere('slug', $slug);
            expect($seeded)->not->toBeNull();
            expect($seeded)
                ->toMatchArray([
                    'monthly_conversations' => 500,
                    'monthly_messages' => 5000,
                    'price_cents' => 4900,
                    'yearly_price_cents' => 49000,
                    'agents_limit' => 3,
                    'sources_limit' => 50,
                    'workflows_limit' => 10,
                    'integrations_limit' => 5,
                    'members_limit' => 5,
                    'workspaces_limit' => 1,
                    'api_access' => true,
                    'remove_branding' => true,
                ]);
        });
});

test('plan without yearly_price_cents still serves a complete payload', function () {
    ['user' => $user] = billingOwner();
    $slug = 'test-no-yearly-'.bin2hex(random_bytes(3));

    Plan::query()->create([
        'name' => 'Test No Yearly',
        'slug' => $slug,
        'monthly_conversations' => 99,
        'price_cents' => 0,
        'yearly_price_cents' => null,
        'agents_limit' => 1,
        'sources_limit' => 5,
        'api_access' => false,
        'is_active' => true,
        'show_on_pricing_page' => true,
    ]);

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk()
        ->assertInertia(function ($p) use ($slug) {
            $plans = $p->toArray()['props']['plans'];
            $row = collect($plans)->firstWhere('slug', $slug);
            expect($row)->not->toBeNull();
            expect($row['yearly_price_cents'])->toBeNull();
            expect($row['api_access'])->toBeFalse();
            expect($row['agents_limit'])->toBe(1);
        });
});
