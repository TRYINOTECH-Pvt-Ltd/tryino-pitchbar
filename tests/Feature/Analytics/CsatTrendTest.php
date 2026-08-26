<?php

use App\Models\Conversation;
use App\Models\Visitor;

function csatConversation(string $agentId, string $satisfaction, ?string $comment = null, ?DateTimeInterface $when = null): Conversation
{
    $visitor = Visitor::factory()->create(['agent_id' => $agentId]);

    return Conversation::factory()->create([
        'agent_id' => $agentId,
        'visitor_id' => $visitor->id,
        'satisfaction' => $satisfaction,
        'satisfaction_at' => $when ?? now(),
        'satisfaction_comment' => $comment,
    ]);
}

test('csat payload reports latest score for the workspace', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);

    csatConversation($agent->id, 'good');
    csatConversation($agent->id, 'good');
    csatConversation($agent->id, 'bad');

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('csat.total_rated', 3)
            ->where('csat.latest_score', 66.7));
});

test('csat low-rated list shows recent bad conversations only', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);

    $oldBad = csatConversation($agent->id, 'bad', 'Outdated', now()->subDays(60));
    $recentBad = csatConversation($agent->id, 'bad', 'Bot was wrong', now()->subDays(2));
    csatConversation($agent->id, 'good', 'Loved it');

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('csat.low_rated', fn ($rows) => collect($rows)->pluck('id')->all() === [$recentBad->id]));
});

test('csat data is workspace-isolated', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);

    csatConversation($agent->id, 'good');
    csatConversation($foreign['agent']->id, 'bad', 'foreign');

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('csat.total_rated', 1)
            ->where('csat.low_rated', []));
});

test('csat series has 12 buckets', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    csatConversation($agent->id, 'good');

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('csat.series', fn ($series) => count($series) === 12));
});

test('csat payload absent of ratings stays empty', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('csat.total_rated', 0)
            ->where('csat.latest_score', null));
});

test('csat counts widget-flavoured positive/negative ratings, not just good/bad', function () {
    // The widget POSTs rating values 'positive' / 'negative' to
    // /api/v1/widget/satisfaction; SatisfactionController stores them
    // verbatim. The analytics reader previously hard-coded 'good' /
    // 'bad' so every real visitor rating slipped through, pinning CSAT
    // at 0%. This regression guards both vocabularies.
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);

    csatConversation($agent->id, 'positive');
    csatConversation($agent->id, 'positive');
    csatConversation($agent->id, 'negative', 'bot was off');

    $this->actingAs($user)
        ->get('/app/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('csat.total_rated', 3)
            ->where('csat.latest_score', 66.7)
            ->where('csat.low_rated', fn ($rows) => count($rows) === 1));
});
