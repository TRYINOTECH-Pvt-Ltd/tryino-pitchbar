<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Billing\BillingHistory;

function billingOwnerWithWorkspace(?array $workspaceOverrides = null): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(array_merge(
        ['owner_user_id' => $user->id],
        $workspaceOverrides ?? [],
    ));
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

test('billing page renders for an authed workspace owner with the new history props', function () {
    ['user' => $user] = billingOwnerWithWorkspace();

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/billing')
            ->has('invoices')
            ->has('charges')
            ->has('lifetime'),
        );
});

test('billing.lifetime is null for a workspace without a lifetime purchase', function () {
    ['user' => $user] = billingOwnerWithWorkspace();

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertInertia(fn ($page) => $page->where('lifetime', null));
});

test('billing.lifetime surfaces the LTD details once the workspace owns one', function () {
    $ltd = Plan::factory()->create([
        'name' => 'Founder Lifetime',
        'billing_model' => Plan::BILLING_LIFETIME,
        'price_cents' => 39900,
    ]);

    ['user' => $user, 'workspace' => $workspace] = billingOwnerWithWorkspace([
        'lifetime_plan_id' => $ltd->id,
        'lifetime_purchased_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertInertia(fn ($page) => $page
            ->where('lifetime.plan_name', 'Founder Lifetime')
            ->has('lifetime.purchased_at'),
        );
});

test('BillingHistory returns the lifetime charge when one exists', function () {
    $ltd = Plan::factory()->create([
        'name' => 'Founder Lifetime',
        'billing_model' => Plan::BILLING_LIFETIME,
        'price_cents' => 39900,
    ]);
    ['workspace' => $workspace] = billingOwnerWithWorkspace([
        'lifetime_plan_id' => $ltd->id,
        'lifetime_purchased_at' => now(),
    ]);

    $charges = (new BillingHistory)->chargesFor($workspace);
    expect($charges)->toHaveCount(1);
    expect($charges[0]['source'])->toBe('lifetime');
    expect($charges[0]['total_cents'])->toBe(39900);
    expect($charges[0]['description'])->toContain('Founder Lifetime');
});

test('BillingHistory returns empty invoices for a workspace with no Stripe customer', function () {
    ['workspace' => $workspace] = billingOwnerWithWorkspace();

    expect((new BillingHistory)->invoicesFor($workspace))->toBe([]);
});
