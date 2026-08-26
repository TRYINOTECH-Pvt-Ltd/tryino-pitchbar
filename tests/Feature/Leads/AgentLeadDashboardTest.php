<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;

function agentLeadFixture(string $agentId, array $leadOverrides = [], array $conversationOverrides = []): Lead
{
    $visitor = Visitor::factory()->create(['agent_id' => $agentId]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agentId,
        'visitor_id' => $visitor->id,
        ...$conversationOverrides,
    ]);

    return Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agentId,
        'email' => 'owner@example.com',
        'name' => 'Primary Lead',
        'phone' => null,
        'status' => 'new',
        'fields' => [],
        ...$leadOverrides,
    ]);
}

test('owner sees an agent-only leads page with isolated totals', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    $secondAgent = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Support Bot']);
    $foreign = workspaceMemberWithAgent(['role' => 'owner'], ['name' => 'Foreign Bot']);

    agentLeadFixture($agent->id, [
        'email' => 'pipeline@example.com',
        'name' => 'Pipeline Lead',
        'phone' => '+15550101',
        'status' => 'qualified',
    ], ['page_url' => 'https://example.test/pricing']);
    agentLeadFixture($agent->id, [
        'email' => null,
        'name' => 'Callback Lead',
        'status' => 'new',
    ], ['page_url' => 'https://example.test/demo']);
    agentLeadFixture($secondAgent->id, [
        'email' => 'support@example.com',
        'name' => 'Support Lead',
        'status' => 'won',
    ]);
    agentLeadFixture($foreign['agent']->id, [
        'email' => 'foreign@example.com',
        'name' => 'Foreign Lead',
        'status' => 'qualified',
    ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/leads")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/agents/leads')
            ->where('agent.id', $agent->id)
            ->where('totals.leads', 2)
            ->where('totals.qualified', 1)
            ->where('totals.with_email', 1)
            ->where('totals.with_phone', 1)
            ->where('leads', fn ($rows) => count($rows) === 2
                && collect($rows)->pluck('name')->sort()->values()->all() === ['Callback Lead', 'Pipeline Lead']),
        );
});

test('agent leads search stays inside the selected agent', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    $secondAgent = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Support Bot']);

    agentLeadFixture($agent->id, [
        'email' => 'priority@target.test',
        'name' => 'Target Lead',
    ], ['page_url' => 'https://example.test/pricing']);
    agentLeadFixture($agent->id, [
        'email' => 'neutral@example.test',
        'name' => 'Neutral Lead',
    ]);
    agentLeadFixture($secondAgent->id, [
        'email' => 'also-target@target.test',
        'name' => 'Foreign In Workspace',
    ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/leads?q=target")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.q', 'target')
            ->where('leads', fn ($rows) => count($rows) === 1
                && $rows[0]['name'] === 'Target Lead'),
        );
});
