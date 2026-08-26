<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Source;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

function dashUser(): array
{
    $user = User::factory()->create();
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    return ['user' => $user, 'workspace' => $ws];
}

function makeConversation(Agent $agent, ?CarbonInterface $startedAt = null, bool $playground = false): Conversation
{
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    return Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => $startedAt ?? now(),
        'is_playground' => $playground,
    ]);
}

function addMessage(Conversation $conversation, string $role, string $content, ?CarbonInterface $createdAt = null): void
{
    $timestamp = $createdAt ?? now();

    $message = new Message;
    $message->forceFill([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversation->id,
        'role' => $role,
        'content' => $content,
        'citations' => [],
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
        'model' => null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $message->saveQuietly();
}

test('dashboard renders with zero stats for an empty workspace', function () {
    ['user' => $user] = dashUser();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->component('dashboard')
        ->where('stats.conversations.total', 0)
        ->where('stats.leads.total', 0)
        ->where('stats.sources.indexed', 0)
        ->where('stats.agents.total', 0)
    );
});

test('dashboard reports counts within and outside the 7d / 30d windows', function () {
    ['user' => $user, 'workspace' => $ws] = dashUser();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    // 2 in last 7d
    makeConversation($agent, now()->subDays(1));
    makeConversation($agent, now()->subDays(3));
    // 1 in 8–30d
    makeConversation($agent, now()->subDays(15));
    // 1 older than 30d
    makeConversation($agent, now()->subDays(45));
    // playground convo — should be excluded
    makeConversation($agent, now()->subDays(1), playground: true);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('stats.conversations.total', 4)
        ->where('stats.conversations.last_7d', 2)
        ->where('stats.conversations.last_30d', 3)
    );
});

test('dashboard counts source statuses correctly', function () {
    ['user' => $user, 'workspace' => $ws] = dashUser();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'indexed']);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'indexed']);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'crawling']);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'pending']);
    Source::factory()->create(['agent_id' => $agent->id, 'status' => 'failed']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('stats.sources.indexed', 2)
        ->where('stats.sources.in_progress', 2)
        ->where('stats.sources.failed', 1)
    );
});

test('dashboard surfaces the 5 most recent leads', function () {
    ['user' => $user, 'workspace' => $ws] = dashUser();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    foreach (range(1, 7) as $i) {
        $convo = makeConversation($agent, now()->subDays($i));
        Lead::create([
            'conversation_id' => $convo->id,
            'agent_id' => $agent->id,
            'email' => "v{$i}@example.com",
            'status' => 'new',
            'fields' => [],
        ]);
    }

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('stats.leads.total', 7)
        ->has('stats.recent_leads', 5)
    );
});

test('dashboard recent leads respect lead filters and sort', function () {
    ['user' => $user, 'workspace' => $ws] = dashUser();
    $alphaAgent = Agent::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Alpha Agent',
    ]);
    $zuluAgent = Agent::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Zulu Agent',
    ]);

    $alphaConversation = makeConversation($alphaAgent, now()->subDay());
    $zuluConversation = makeConversation($zuluAgent, now()->subHours(20));
    $missingEmailConversation = makeConversation($alphaAgent, now()->subHours(12));
    $newConversation = makeConversation($zuluAgent, now()->subHours(6));

    Lead::create([
        'conversation_id' => $alphaConversation->id,
        'agent_id' => $alphaAgent->id,
        'email' => 'alpha@example.com',
        'name' => 'Alpha Lead',
        'status' => 'qualified',
        'fields' => [],
    ]);
    Lead::create([
        'conversation_id' => $zuluConversation->id,
        'agent_id' => $zuluAgent->id,
        'email' => 'zulu@example.com',
        'name' => 'Zulu Lead',
        'status' => 'qualified',
        'fields' => [],
    ]);
    Lead::create([
        'conversation_id' => $missingEmailConversation->id,
        'agent_id' => $alphaAgent->id,
        'email' => null,
        'name' => 'No Email Lead',
        'status' => 'qualified',
        'fields' => [],
    ]);
    Lead::create([
        'conversation_id' => $newConversation->id,
        'agent_id' => $zuluAgent->id,
        'email' => 'new@example.com',
        'name' => 'Newest Lead',
        'status' => 'new',
        'fields' => [],
    ]);

    $response = $this->actingAs($user)->get(
        '/dashboard?lead_status=qualified&lead_contact=with_email&lead_sort=name_asc',
    );

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('filters.lead_status', 'qualified')
        ->where('filters.lead_contact', 'with_email')
        ->where('filters.lead_sort', 'name_asc')
        ->has('stats.recent_leads', 2)
        ->where('stats.recent_leads.0.name', 'Alpha Lead')
        ->where('stats.recent_leads.0.agent.name', 'Alpha Agent')
        ->where('stats.recent_leads.1.name', 'Zulu Lead')
        ->where('stats.recent_leads.1.agent.name', 'Zulu Agent'));
});

test('dashboard ships a 7-element series and previous_7d for trend calculation', function () {
    ['user' => $user, 'workspace' => $ws] = dashUser();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    // Last 7d window: 4 conversations
    foreach ([0, 1, 2, 5] as $d) {
        makeConversation($agent, now()->subDays($d));
    }
    // Prior 7-day window (8–13d ago): 2 conversations
    foreach ([8, 12] as $d) {
        makeConversation($agent, now()->subDays($d));
    }

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('stats.conversations.last_7d', 4)
        ->where('stats.conversations.previous_7d', 2)
        ->has('stats.conversations.series_7d', 7)
    );
});

test('dashboard surfaces robust reports and chart data without leaking foreign tenants', function () {
    ['user' => $user, 'workspace' => $ws] = dashUser();
    $alphaAgent = Agent::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Alpha Agent',
        'is_published' => true,
    ]);
    $zuluAgent = Agent::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Zulu Agent',
        'is_published' => false,
    ]);

    $alphaConversationA = makeConversation($alphaAgent, now()->subDay());
    $alphaConversationB = makeConversation($alphaAgent, now()->subDays(5));
    $zuluConversation = makeConversation($zuluAgent, now()->subDays(2));
    $playgroundConversation = makeConversation($alphaAgent, now()->subHours(10), playground: true);

    foreach (['Need pricing', 'Still comparing', 'Please call me'] as $index => $content) {
        addMessage($alphaConversationA, $index === 1 ? 'assistant' : 'user', $content, now()->subDay()->addMinutes($index));
    }

    foreach (['Annual billing?', 'Yes, supported'] as $index => $content) {
        addMessage($alphaConversationB, $index === 1 ? 'assistant' : 'user', $content, now()->subDays(5)->addMinutes($index));
    }

    foreach (['How do refunds work?', 'Within fourteen days', 'Can a human reply?', 'Yes'] as $index => $content) {
        addMessage($zuluConversation, $index % 2 === 1 ? 'assistant' : 'user', $content, now()->subDays(2)->addMinutes($index));
    }

    addMessage($playgroundConversation, 'user', 'Playground prompt', now()->subHours(10));
    addMessage($playgroundConversation, 'assistant', 'Playground response', now()->subHours(10)->addMinute());

    Lead::create([
        'conversation_id' => $alphaConversationA->id,
        'agent_id' => $alphaAgent->id,
        'email' => 'alpha@example.com',
        'name' => 'Alpha Lead',
        'status' => 'qualified',
        'fields' => [],
    ]);
    Lead::create([
        'conversation_id' => $zuluConversation->id,
        'agent_id' => $zuluAgent->id,
        'email' => 'zulu@example.com',
        'name' => 'Zulu Lead',
        'status' => 'new',
        'fields' => [],
    ]);

    Source::factory()->create(['agent_id' => $alphaAgent->id, 'status' => 'indexed']);
    Source::factory()->create(['agent_id' => $alphaAgent->id, 'status' => 'crawling']);
    Source::factory()->create(['agent_id' => $zuluAgent->id, 'status' => 'failed']);

    $foreignWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Foreign Agent']);
    $foreignConversation = makeConversation($foreignAgent, now()->subDay());
    addMessage($foreignConversation, 'user', 'Foreign visitor', now()->subDay());
    Lead::create([
        'conversation_id' => $foreignConversation->id,
        'agent_id' => $foreignAgent->id,
        'email' => 'foreign@example.com',
        'name' => 'Foreign Lead',
        'status' => 'won',
        'fields' => [],
    ]);
    Source::factory()->create(['agent_id' => $foreignAgent->id, 'status' => 'indexed']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('stats.messages.total', 9)
        ->where('stats.messages.last_7d', 9)
        ->where('stats.messages.last_30d', 9)
        ->where('reports.conversion.value', 66.7)
        ->where('reports.engagement.value', 3)
        ->where('reports.source_health.total', 3)
        ->where('reports.source_health.indexed', 1)
        ->where('reports.source_health.in_progress', 1)
        ->where('reports.source_health.failed', 1)
        ->has('chart.days', 30)
        ->has('chart.conversations', 30)
        ->where('reports.pipeline.total', 2)
        ->where('reports.pipeline.statuses', fn ($rows) => collect($rows)
            ->pluck('value', 'key')
            ->all() === [
                'new' => 1,
                'qualified' => 1,
                'contacted' => 0,
                'won' => 0,
                'lost' => 0,
            ])
        ->has('reports.top_agents', 2)
        ->where('reports.top_agents.0.name', 'Alpha Agent')
        ->where('reports.top_agents.0.conversations', 2)
        ->where('reports.top_agents.0.leads', 1)
        ->where('reports.top_agents.1.name', 'Zulu Agent')
    );
});

test('dashboard returns recent agents with publish status', function () {
    ['user' => $user, 'workspace' => $ws] = dashUser();
    Agent::factory()->create(['workspace_id' => $ws->id, 'name' => 'Sales bot', 'is_published' => true]);
    Agent::factory()->create(['workspace_id' => $ws->id, 'name' => 'Support draft', 'is_published' => false]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->has('stats.agents.list', 2)
        ->where('stats.agents.published', 1)
        ->where('stats.agents.total', 2)
    );
});

test('dashboard does NOT leak counts from another workspace', function () {
    ['user' => $user] = dashUser();

    // Other workspace with lots of activity — should be invisible.
    $other = Workspace::factory()->create();
    $otherAgent = Agent::factory()->create(['workspace_id' => $other->id]);
    foreach (range(1, 5) as $_) {
        makeConversation($otherAgent);
    }
    Source::factory()->create(['agent_id' => $otherAgent->id, 'status' => 'indexed']);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('stats.conversations.total', 0)
        ->where('stats.messages.total', 0)
        ->where('stats.sources.indexed', 0)
        ->where('reports.top_agents', [])
    );
});
