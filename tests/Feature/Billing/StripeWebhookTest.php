<?php

use App\Mail\PlanChangedMail;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
        ['name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000, 'price_cents' => 24900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }

    // Disable signature verification for tests — Cashier skips it when the
    // secret is empty.
    config()->set('cashier.webhook.secret', '');
});

test('subscription.created webhook flips workspace.plan_id to the matching plan', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['stripe_price_id' => 'price_standard_test'])->save();

    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'stripe_id' => 'cus_test_123',
    ]);

    $payload = [
        'id' => 'evt_test_'.uniqid(),
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_test_abc',
            'customer' => 'cus_test_123',
            'status' => 'active',
            'metadata' => [],
            'items' => ['data' => [
                ['id' => 'si_1', 'price' => ['id' => 'price_standard_test', 'product' => 'prod_test'], 'quantity' => 1],
            ]],
        ]],
    ];

    $this->postJson('/billing/webhook', $payload)
        ->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});

test('subscription.updated to a different price swaps the workspace plan', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['stripe_price_id' => 'price_standard'])->save();
    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();
    $pro->forceFill(['stripe_price_id' => 'price_pro'])->save();

    $workspace = Workspace::factory()->create([
        'plan_id' => $standard->id,
        'stripe_id' => 'cus_swap_test',
    ]);

    $payload = [
        'id' => 'evt_test_'.uniqid(),
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_swap_test',
            'customer' => 'cus_swap_test',
            'status' => 'active',
            'metadata' => [],
            'cancel_at_period_end' => false,
            'items' => ['data' => [
                ['id' => 'si_2', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1],
            ]],
        ]],
    ];

    $this->postJson('/billing/webhook', $payload)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($pro->id);
});

test('subscription.updated emails the customer when their plan changes', function () {
    Mail::fake();

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['stripe_price_id' => 'price_standard'])->save();
    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();
    $pro->forceFill(['stripe_price_id' => 'price_pro'])->save();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'plan_id' => $standard->id,
        'stripe_id' => 'cus_swap_mail',
        'owner_user_id' => $owner->id,
    ]);

    $payload = [
        'id' => 'evt_test_'.uniqid(),
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_swap_mail',
            'customer' => 'cus_swap_mail',
            'status' => 'active',
            'metadata' => [],
            'cancel_at_period_end' => false,
            'items' => ['data' => [
                ['id' => 'si_x', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1],
            ]],
        ]],
    ];

    $this->postJson('/billing/webhook', $payload)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($pro->id);

    // Queued (ShouldQueue) to the workspace owner, naming the old + new plan.
    Mail::assertQueued(PlanChangedMail::class, function ($mail) use ($owner) {
        return $mail->hasTo($owner->email)
            && $mail->previousPlanName === 'Standard'
            && $mail->newPlanName === 'Pro';
    });
});

test('subscription.updated with no plan change sends no email', function () {
    // Renewals + card/status updates fire subscription.updated constantly;
    // the customer must only be emailed when the plan actually changes.
    Mail::fake();

    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();
    $pro->forceFill(['stripe_price_id' => 'price_pro'])->save();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'plan_id' => $pro->id,
        'stripe_id' => 'cus_nochange',
        'owner_user_id' => $owner->id,
    ]);

    $payload = [
        'id' => 'evt_test_'.uniqid(),
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_nochange',
            'customer' => 'cus_nochange',
            'status' => 'active',
            'metadata' => [],
            'cancel_at_period_end' => false,
            'items' => ['data' => [
                ['id' => 'si_y', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1],
            ]],
        ]],
    ];

    $this->postJson('/billing/webhook', $payload)->assertOk();

    Mail::assertNotQueued(PlanChangedMail::class);
});

test('subscription.deleted webhook reverts workspace to the free plan', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $workspace = Workspace::factory()->create([
        'plan_id' => $standard->id,
        'stripe_id' => 'cus_cancel_test',
    ]);

    $payload = [
        'id' => 'evt_test_'.uniqid(),
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'id' => 'sub_cancel_test',
            'customer' => 'cus_cancel_test',
            'status' => 'canceled',
            'items' => ['data' => []],
        ]],
    ];

    $this->postJson('/billing/webhook', $payload)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($free->id);
});

test('webhook ignores unknown stripe customer ids', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['stripe_price_id' => 'price_standard'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'stripe_id' => 'cus_known',
    ]);

    $payload = [
        'id' => 'evt_test_'.uniqid(),
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_test_abc',
            'customer' => 'cus_unknown_xyz',
            'status' => 'active',
            'metadata' => [],
            'items' => ['data' => [
                ['id' => 'si_3', 'price' => ['id' => 'price_standard', 'product' => 'prod_x'], 'quantity' => 1],
            ]],
        ]],
    ];

    $this->postJson('/billing/webhook', $payload)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($free->id);
});
