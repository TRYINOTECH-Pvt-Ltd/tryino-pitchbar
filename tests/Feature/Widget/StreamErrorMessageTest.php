<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Widget\WidgetJwt;

/**
 * Buyer-reported (Jamiu, 2026-05-20): widget chat suddenly stopped
 * working. UI showed "Sorry — something went wrong." three times.
 * Two root causes:
 *
 *   1. Widget retried 3 times on permanent errors (agent_not_found,
 *      conversation_not_found, etc) instead of surfacing them. Fixed
 *      client-side in Bar.tsx.
 *
 *   2. Server emitted `error` events with `code` only — no
 *      visitor-facing `message`. Even when the widget DID surface
 *      the error it had nothing to display. This file asserts every
 *      visitor-facing error path carries a human-readable `message`.
 */
function streamErrorEnv(): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return [
        'agent' => $agent,
        'conv' => $conv,
        'jwt' => $jwt['token'],
        'workspace' => $workspace,
    ];
}

test('missing conversation error event carries a visitor-friendly message', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    // Issue a JWT for a conversation id that doesn't exist in the DB.
    $jwt = app(WidgetJwt::class)
        ->issue($agent->id, $visitor->id, '019dead-dead-dead-dead-deaddeaddead');

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt['token']}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Hi']);

    $body = $response->streamedContent();
    expect($body)->toContain('event: error');
    expect($body)->toContain('conversation_not_found');
    expect($body)->toContain('refresh');
});

test('invalid token returns a visitor-friendly error stream', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer not-a-real-jwt'])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Hi']);

    $body = $response->streamedContent();
    expect($body)->toContain('event: error');
    expect($body)->toContain('invalid_token');
});
