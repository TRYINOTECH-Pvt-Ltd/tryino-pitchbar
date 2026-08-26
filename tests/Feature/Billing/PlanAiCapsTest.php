<?php

use App\Enums\PlatformRole;
use App\Jobs\Analytics\IncrementUsageJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Billing\MeteredBilling;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Plan::query()->delete();
});

test('plan-form admin update accepts the new AI cap dials', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-caps',
        'monthly_conversations' => 3000,
        'price_cents' => 24900,
        'is_active' => true,
    ]);

    $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", [
        'name' => 'Pro',
        'monthly_conversations' => 3000,
        'monthly_messages' => 25000,
        'max_tokens_per_response' => 1500,
        'price_cents' => 24900,
        'is_active' => true,
    ])->assertRedirect();

    $plan->refresh();
    expect($plan->monthly_messages)->toBe(25000);
    expect($plan->max_tokens_per_response)->toBe(1500);
});

test('max_tokens_per_response below 100 is rejected', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $plan = Plan::create([
        'name' => 'Free',
        'slug' => 'free-caps',
        'monthly_conversations' => 100,
        'price_cents' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", [
        'name' => 'Free',
        'monthly_conversations' => 100,
        'monthly_messages' => null,
        'max_tokens_per_response' => 50,
        'price_cents' => 0,
        'is_active' => true,
    ])->assertSessionHasErrors('max_tokens_per_response');
});

test('MeteredBilling::canSendMessage gates on monthly_messages once exceeded', function () {
    $plan = Plan::create([
        'name' => 'Capped',
        'slug' => 'capped-msgs',
        'monthly_conversations' => 1000,
        'monthly_messages' => 5,
        'price_cents' => 0,
        'is_active' => true,
    ]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    $billing = new MeteredBilling;
    expect($billing->canSendMessage($workspace))->toBeTrue();

    foreach (range(1, 5) as $i) {
        UsageEvent::create([
            'workspace_id' => $workspace->id,
            'kind' => 'message',
            'quantity' => 1,
            'meta' => [],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    expect($billing->canSendMessage($workspace))->toBeFalse();
});

test('null monthly_messages = no per-message cap, never gates', function () {
    $plan = Plan::create([
        'name' => 'Uncapped',
        'slug' => 'uncapped-msgs',
        'monthly_conversations' => 10,
        'monthly_messages' => null,
        'price_cents' => 0,
        'is_active' => true,
    ]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    foreach (range(1, 100) as $i) {
        UsageEvent::create([
            'workspace_id' => $workspace->id,
            'kind' => 'message',
            'quantity' => 1,
            'meta' => [],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    expect((new MeteredBilling)->canSendMessage($workspace))->toBeTrue();
});

test('maxTokensFor returns the plan setting and falls back to null when blank', function () {
    $billing = new MeteredBilling;

    $plan = Plan::create([
        'name' => 'Capped',
        'slug' => 'tok-cap',
        'monthly_conversations' => 1000,
        'max_tokens_per_response' => 600,
        'price_cents' => 0,
        'is_active' => true,
    ]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);
    expect($billing->maxTokensFor($workspace))->toBe(600);

    $plan2 = Plan::create([
        'name' => 'Wild',
        'slug' => 'tok-wild',
        'monthly_conversations' => 1000,
        'max_tokens_per_response' => null,
        'price_cents' => 0,
        'is_active' => true,
    ]);
    $workspace2 = Workspace::factory()->create(['plan_id' => $plan2->id]);
    expect($billing->maxTokensFor($workspace2))->toBeNull();
});

test('IncrementUsageJob writes both conversation + message usage rows', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    Cache::flush();
    (new IncrementUsageJob($conversation->id))->handle();

    expect(UsageEvent::query()->where('workspace_id', $workspace->id)->where('kind', 'conversation')->count())->toBe(1);
    expect(UsageEvent::query()->where('workspace_id', $workspace->id)->where('kind', 'message')->count())->toBe(1);

    // Re-running the job (queue retry / dup dispatch) does NOT double the
    // conversation count, but DOES count another message.
    (new IncrementUsageJob($conversation->id))->handle();
    expect(UsageEvent::query()->where('workspace_id', $workspace->id)->where('kind', 'conversation')->count())->toBe(1);
    expect(UsageEvent::query()->where('workspace_id', $workspace->id)->where('kind', 'message')->count())->toBe(2);
});
