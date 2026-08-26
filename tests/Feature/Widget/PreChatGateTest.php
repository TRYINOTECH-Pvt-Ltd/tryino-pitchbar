<?php

use App\Models\Agent;
use App\Models\Lead;

/**
 * Pre-chat name/email gate (`require_lead_before_chat`). When the
 * agent has it on, the widget renders a Name + Email form first, then
 * unlocks the chat surface. Server-side responsibilities tested here:
 *
 *   - Admin can toggle the column via PATCH /app/agents/{id}.
 *   - /widget/init exposes `agent.require_lead_before_chat` so the
 *     widget knows whether to gate.
 *   - /widget/init exposes `lead_captured` so a returning visitor
 *     doesn't see the gate twice.
 */
test('PATCH /app/agents/{id} accepts require_lead_before_chat', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->patch("/app/agents/{$agent->id}", [
        'require_lead_before_chat' => true,
    ])->assertRedirect();

    expect($agent->fresh()->require_lead_before_chat)->toBeTrue();
});

test('init exposes require_lead_before_chat flag', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
        'require_lead_before_chat' => true,
    ]);

    $response = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk();
    expect($response->json('data.agent.require_lead_before_chat'))->toBeTrue();
    expect($response->json('data.lead_captured'))->toBeFalse();
});

test('init defaults require_lead_before_chat to false on a fresh agent', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
    ]);

    $response = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk();
    expect($response->json('data.agent.require_lead_before_chat'))->toBeFalse();
});

test('init sets lead_captured=true once a lead is on the conversation — stops the gate from showing again on refresh', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://shop.example.com'],
        'require_lead_before_chat' => true,
    ]);

    // First init mints the conversation.
    $first = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'anon_id' => 'anon-pagenet-1',
        ])->assertOk();

    $conversationId = $first->json('data.conversation_id');
    expect($first->json('data.lead_captured'))->toBeFalse();

    // Capture happens via /v1/widget/leads — replicate the row directly.
    Lead::query()->withoutGlobalScopes()->create([
        'workspace_id' => $agent->workspace_id,
        'agent_id' => $agent->id,
        'conversation_id' => $conversationId,
        'email' => 'pagenet@example.com',
        'name' => 'Pagenet',
    ]);

    // Returning visitor (same anon_id) re-inits — gate must not re-show.
    $second = $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'anon_id' => 'anon-pagenet-1',
        ])->assertOk();

    expect($second->json('data.conversation_id'))->toBe($conversationId);
    expect($second->json('data.lead_captured'))->toBeTrue();
});
