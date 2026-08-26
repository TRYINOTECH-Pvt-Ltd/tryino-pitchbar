<?php

use App\Models\Agent;
use App\Models\ContentGap;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function analyticsConversationFixture(
    string $agentId,
    array $messages = [],
    array $conversationOverrides = [],
    ?array $leadOverrides = null,
): Conversation {
    $visitor = Visitor::factory()->create(['agent_id' => $agentId]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agentId,
        'visitor_id' => $visitor->id,
        ...$conversationOverrides,
    ]);

    foreach ($messages as $message) {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'role' => $message['role'],
            'content' => $message['content'],
            'citations' => json_encode($message['citations'] ?? []),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'model' => null,
            'confidence' => $message['confidence'] ?? null,
            'created_at' => $message['at'] ?? ($conversationOverrides['started_at'] ?? now()),
        ]);
    }

    if ($leadOverrides !== null) {
        $leadCreatedAt = $leadOverrides['created_at'] ?? ($conversationOverrides['started_at'] ?? now());
        unset($leadOverrides['created_at']);

        $lead = Lead::create([
            'conversation_id' => $conversation->id,
            'agent_id' => $agentId,
            'email' => 'lead@example.com',
            'name' => 'Lead',
            'status' => 'new',
            'fields' => [],
            ...$leadOverrides,
        ]);

        $lead->forceFill([
            'created_at' => $leadCreatedAt,
            'updated_at' => $leadCreatedAt,
        ])->saveQuietly();
    }

    return $conversation;
}

test('owner sees workspace analytics overview with scoped metrics and breakdowns', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(
        ['role' => 'owner'],
        ['name' => 'Sales Copilot', 'is_published' => true],
    );
    $supportAgent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Support Copilot',
        'is_published' => false,
    ]);
    $foreign = workspaceMemberWithAgent(['role' => 'owner'], ['name' => 'Foreign Bot']);

    analyticsConversationFixture(
        $agent->id,
        [
            ['role' => 'user', 'content' => 'What does the pro plan cost?'],
            ['role' => 'assistant', 'content' => 'It starts at $99.'],
            ['role' => 'user', 'content' => 'Can sales contact me?'],
        ],
        [
            'page_url' => 'https://example.test/pricing',
            'started_at' => now()->subDay(),
        ],
        [
            'email' => 'buyer@example.com',
            'status' => 'qualified',
        ],
    );
    analyticsConversationFixture(
        $agent->id,
        [
            ['role' => 'user', 'content' => 'Do you support annual billing?'],
            ['role' => 'assistant', 'content' => 'Yes, we do.'],
        ],
        [
            'page_url' => 'https://example.test/pricing',
            'started_at' => now()->subDays(4),
        ],
    );
    analyticsConversationFixture(
        $supportAgent->id,
        [
            ['role' => 'user', 'content' => 'How do refunds work?'],
            ['role' => 'assistant', 'content' => 'Refunds are handled within 14 days.'],
            ['role' => 'user', 'content' => 'Can I talk to a person?'],
            ['role' => 'assistant', 'content' => 'Yes, a human can follow up.'],
        ],
        [
            'page_url' => 'https://example.test/help',
            'started_at' => now()->subDays(2),
        ],
        [
            'email' => 'support@example.com',
            'status' => 'new',
        ],
    );
    analyticsConversationFixture(
        $agent->id,
        [
            ['role' => 'user', 'content' => 'Playground prompt'],
            ['role' => 'assistant', 'content' => 'Playground answer'],
        ],
        [
            'page_url' => 'https://example.test/lab',
            'started_at' => now()->subHours(12),
            'is_playground' => true,
        ],
    );
    analyticsConversationFixture(
        $foreign['agent']->id,
        [
            ['role' => 'user', 'content' => 'Foreign secret'],
            ['role' => 'assistant', 'content' => 'Keep this isolated.'],
            ['role' => 'user', 'content' => 'Private follow-up'],
        ],
        [
            'page_url' => 'https://foreign.test',
            'started_at' => now()->subDay(),
        ],
        [
            'email' => 'foreign@example.com',
            'status' => 'won',
        ],
    );

    ContentGap::create([
        'agent_id' => $agent->id,
        'question' => 'How do refunds work?',
        'question_hash' => 'refunds-workspace-gap',
        'occurrences' => 4,
        'last_seen_at' => now()->subHour(),
        'status' => 'open',
    ]);
    ContentGap::create([
        'agent_id' => $supportAgent->id,
        'question' => 'Do you integrate with Zendesk?',
        'question_hash' => 'zendesk-workspace-gap',
        'occurrences' => 2,
        'last_seen_at' => now()->subHours(2),
        'status' => 'answered',
    ]);
    ContentGap::create([
        'agent_id' => $foreign['agent']->id,
        'question' => 'Foreign workspace gap',
        'question_hash' => 'foreign-workspace-gap',
        'occurrences' => 6,
        'last_seen_at' => now(),
        'status' => 'open',
    ]);

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/analytics/index')
            ->where('metrics.conversations.value', 3)
            ->where('metrics.messages.value', 9)
            ->where('metrics.leads.value', 2)
            ->where('metrics.conversion_rate.value', 66.7)
            ->where('summary.active_agents', 2)
            ->where('summary.published_agents', 1)
            ->where('summary.open_gaps', 1)
            ->has('window.days', 30)
            ->has('agents', 2)
            ->where('agents.0.name', 'Sales Copilot')
            ->where('pages.0.page_url', 'https://example.test/pricing')
            ->where('pages.0.conversations', 2)
            ->where('pages.0.leads', 1)
            ->where('content_gaps.open_count', 1)
            ->where('content_gaps.top.0.question', 'How do refunds work?'),
        );
});

test('analytics overview reports prior-period values and 30 day series', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(
        ['role' => 'owner'],
        ['name' => 'Growth Copilot', 'is_published' => true],
    );

    analyticsConversationFixture(
        $agent->id,
        [
            ['role' => 'user', 'content' => 'Current conversation'],
            ['role' => 'assistant', 'content' => 'Current answer'],
            ['role' => 'user', 'content' => 'Current follow up'],
        ],
        [
            'page_url' => 'https://example.test/current',
            'started_at' => now()->subDays(3),
        ],
        [
            'email' => 'current@example.com',
            'status' => 'won',
            'created_at' => now()->subDays(3),
        ],
    );
    analyticsConversationFixture(
        $agent->id,
        [
            ['role' => 'user', 'content' => 'Another current thread'],
        ],
        [
            'page_url' => 'https://example.test/current',
            'started_at' => now()->subDays(8),
        ],
    );
    analyticsConversationFixture(
        $agent->id,
        [
            ['role' => 'user', 'content' => 'Prior window thread'],
            ['role' => 'assistant', 'content' => 'Prior answer'],
        ],
        [
            'page_url' => 'https://example.test/prior',
            'started_at' => now()->subDays(36),
        ],
        [
            'email' => 'prior@example.com',
            'status' => 'qualified',
            'created_at' => now()->subDays(36),
        ],
    );
    analyticsConversationFixture(
        $agent->id,
        [
            ['role' => 'user', 'content' => 'Too old to count'],
        ],
        [
            'page_url' => 'https://example.test/old',
            'started_at' => now()->subDays(75),
        ],
        [
            'email' => 'old@example.com',
            'status' => 'new',
            'created_at' => now()->subDays(75),
        ],
    );

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('metrics.conversations.value', 2)
            ->where('metrics.conversations.prior_value', 1)
            ->where('metrics.messages.value', 4)
            ->where('metrics.messages.prior_value', 2)
            ->where('metrics.leads.value', 1)
            ->where('metrics.leads.prior_value', 1)
            ->where('metrics.conversion_rate.value', 50)
            ->where('metrics.conversion_rate.prior_value', 100)
            ->where('metrics.engagement.value', 2)
            ->where('metrics.engagement.prior_value', 2)
            ->has('metrics.conversations.series', 30)
            ->has('metrics.messages.series', 30)
            ->has('chart.days', 30),
        );
});
