<?php

use App\Models\Agent;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\Workspace;
use Illuminate\Support\Str;

beforeEach(function () {
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }
});

test('widget init returns 429 plan_limit_reached when workspace is over quota', function () {
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    UsageEvent::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'kind' => 'conversation',
        'quantity' => $free->monthly_conversations,
        'occurred_at' => now(),
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'plan_limit_reached');
});

test('widget init succeeds when workspace is under quota', function () {
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    UsageEvent::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'kind' => 'conversation',
        'quantity' => 5,
        'occurred_at' => now(),
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk();
});

test('widget init only counts current-month usage', function () {
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    UsageEvent::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'kind' => 'conversation',
        'quantity' => $free->monthly_conversations + 50,
        'occurred_at' => now()->subMonth(),
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk();
});
