<?php

use App\Models\Agent;
use App\Models\ContentGap;

function makeGap(string $agentId, array $overrides = []): ContentGap
{
    return ContentGap::create(array_merge([
        'agent_id' => $agentId,
        'question' => 'Do you offer student pricing?',
        'question_hash' => hash('sha256', uniqid('q', true)),
        'occurrences' => 1,
        'last_seen_at' => now(),
        'status' => 'open',
    ], $overrides));
}

test('gaps page lists workspace-scoped open gaps only', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);

    makeGap($agent->id, ['question' => 'Mine 1', 'occurrences' => 5]);
    makeGap($agent->id, ['question' => 'Mine 2', 'occurrences' => 2]);
    makeGap($foreign['agent']->id, ['question' => 'Foreign']);

    $this->actingAs($user)
        ->get('/app/analytics/content-gaps')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/analytics/content-gaps')
            ->where('gaps', fn ($gaps) => collect($gaps)->pluck('question')->sort()->values()->all()
                === ['Mine 1', 'Mine 2'])
            ->where('filters.status', 'open'));
});

test('agent filter narrows results to one agent', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $second = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Secondary']);

    makeGap($agent->id, ['question' => 'On primary']);
    makeGap($second->id, ['question' => 'On secondary']);

    $this->actingAs($user)
        ->get("/app/analytics/content-gaps?agent_id={$second->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('gaps', fn ($gaps) => count($gaps) === 1
                && collect($gaps)->first()['question'] === 'On secondary'));
});

test('status filter switches between open and answered', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);

    makeGap($agent->id, ['question' => 'Open one', 'status' => 'open']);
    makeGap($agent->id, ['question' => 'Done one', 'status' => 'answered']);

    $this->actingAs($user)
        ->get('/app/analytics/content-gaps?status=answered')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('gaps', fn ($gaps) => count($gaps) === 1
                && collect($gaps)->first()['question'] === 'Done one'));
});

test('window filter excludes stale gaps', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);

    makeGap($agent->id, ['question' => 'Fresh', 'last_seen_at' => now()->subDays(3)]);
    makeGap($agent->id, ['question' => 'Stale', 'last_seen_at' => now()->subDays(60)]);

    $this->actingAs($user)
        ->get('/app/analytics/content-gaps?window=7')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('gaps', fn ($gaps) => count($gaps) === 1
                && collect($gaps)->first()['question'] === 'Fresh'));
});

test('editor can resolve a gap', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'editor']);
    $gap = makeGap($agent->id);

    $this->actingAs($user)
        ->from('/app/analytics/content-gaps')
        ->post("/app/analytics/gaps/{$gap->id}/resolve", ['action' => 'answered'])
        ->assertRedirect('/app/analytics/content-gaps');

    expect($gap->fresh()->status)->toBe('answered');
});

test('editor can ignore a gap', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'editor']);
    $gap = makeGap($agent->id);

    $this->actingAs($user)
        ->from('/app/analytics/content-gaps')
        ->post("/app/analytics/gaps/{$gap->id}/resolve", ['action' => 'ignore'])
        ->assertRedirect('/app/analytics/content-gaps');

    expect($gap->fresh()->status)->toBe('ignored');
});

test('viewer cannot resolve a gap', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'viewer']);
    $gap = makeGap($agent->id);

    $this->actingAs($user)
        ->post("/app/analytics/gaps/{$gap->id}/resolve", ['action' => 'answered'])
        ->assertForbidden();

    expect($gap->fresh()->status)->toBe('open');
});

test('cannot resolve gap from another workspace', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);
    $gap = makeGap($foreign['agent']->id);

    $this->actingAs($user)
        ->post("/app/analytics/gaps/{$gap->id}/resolve", ['action' => 'answered'])
        ->assertNotFound();

    expect($gap->fresh()->status)->toBe('open');
});

test('agents list scoped to workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent(['role' => 'admin']);
    Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Sales Bot']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin'], ['name' => 'Foreign Bot']);

    $this->actingAs($user)
        ->get('/app/analytics/content-gaps')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('agents', fn ($agents) => collect($agents)
                ->pluck('name')
                ->doesntContain('Foreign Bot')));
});
