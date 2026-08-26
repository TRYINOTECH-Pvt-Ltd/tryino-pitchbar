<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('admin overview returns the full data shape: metrics + chart + right_now + activity', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/dashboard')
            ->has('metrics.users.value')
            ->has('metrics.users.series')
            ->has('metrics.users.prior_30d')
            ->has('metrics.workspaces.value')
            ->has('metrics.agents.value')
            ->has('metrics.agents.published')
            ->has('metrics.leads.value')
            ->has('metrics.conversations.value')
            ->has('metrics.messages.value')
            ->has('chart.days')
            ->has('chart.conversations')
            ->has('chart.messages')
            ->has('reports.growth.days')
            ->has('reports.growth.users')
            ->has('reports.growth.workspaces')
            ->has('reports.growth.agents')
            ->has('reports.funnel', 4)
            ->has('reports.lead_capture.value')
            ->has('reports.lead_capture.series')
            ->has('reports.engagement.value')
            ->has('reports.engagement.series')
            ->has('reports.deployment.published_agents')
            ->has('reports.deployment.utilization_rate')
            ->has('right_now.active_conversations')
            ->has('right_now.messages_today')
            ->has('window.from')
            ->has('window.to')
            ->has('recent_signups')
            ->has('activity'),
        );
});

test('non-admin gets 404 on /admin (no leak of admin overview shape)', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($user)
        ->get('/admin')
        ->assertNotFound();
});

test('chart series length matches the 30-day window', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('chart.days', fn ($days) => count($days) === 30)
            ->where('chart.conversations', fn ($s) => count($s) === 30)
            ->where('chart.messages', fn ($s) => count($s) === 30)
            ->where('reports.growth.users', fn ($s) => count($s) === 30)
            ->where('reports.growth.workspaces', fn ($s) => count($s) === 30)
            ->where('reports.growth.agents', fn ($s) => count($s) === 30)
            ->where('reports.lead_capture.series', fn ($s) => count($s) === 30)
            ->where('reports.engagement.series', fn ($s) => count($s) === 30),
        );
});

test('overview reports derive lead capture engagement and deployment health from live data', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'is_published' => true,
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    $leadConversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subDay(),
        'is_playground' => false,
    ]);
    $plainConversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subHours(6),
        'is_playground' => false,
    ]);

    Lead::query()->create([
        'conversation_id' => $leadConversation->id,
        'agent_id' => $agent->id,
        'email' => 'owner@example.com',
        'phone' => null,
        'name' => 'Owner',
        'fields' => [],
        'status' => 'new',
        'owner_user_id' => null,
        'routed_to' => null,
    ]);

    foreach ([$leadConversation->id, $plainConversation->id] as $conversationId) {
        foreach (range(1, 2) as $_) {
            DB::table('messages')->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversationId,
                'role' => 'user',
                'content' => 'hi',
                'citations' => json_encode([]),
                'tokens_in' => 0,
                'tokens_out' => 0,
                'latency_ms' => 0,
                'model' => null,
                'created_at' => now()->subHours(2),
            ]);
        }
    }

    $expectedWorkspaceCoverageRate = round(
        100 / Workspace::query()->withoutGlobalScopes()->count(),
        1,
    );

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('reports.lead_capture.value', fn ($value) => (float) $value === 50.0)
            ->where('reports.engagement.value', fn ($value) => (float) $value === 2.0)
            ->where('reports.deployment.published_agents', 1)
            ->where('reports.deployment.active_published_agents', 1)
            ->where('reports.deployment.utilization_rate', fn ($value) => (float) $value === 100.0)
            ->where('reports.deployment.workspaces_with_traffic', 1)
            ->where('reports.deployment.workspace_coverage_rate', fn ($value) => (float) $value === $expectedWorkspaceCoverageRate),
        );
});

test('right_now active_conversations counts conversations started in the last hour', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subMinutes(5),
        'ended_at' => null,
    ]);
    // An older one and an ended one — neither should count.
    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subDays(2),
        'ended_at' => null,
    ]);
    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinute(),
    ]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertInertia(fn ($page) => $page->where('right_now.active_conversations', 1));
});

test('messages_today counts messages created since today UTC midnight', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);

    // Eloquent auto-stamps created_at, so use raw insert for time-travel.
    foreach (range(1, 3) as $_) {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => 'hi',
            'citations' => json_encode([]),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'model' => null,
            'created_at' => now()->startOfDay()->addHour(),
        ]);
    }
    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conv->id,
        'role' => 'user',
        'content' => 'old',
        'citations' => json_encode([]),
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
        'model' => null,
        'created_at' => now()->subDays(2),
    ]);

    $this->actingAs($admin)
        ->get('/admin')
        ->assertInertia(fn ($page) => $page->where('right_now.messages_today', 3));
});
