<?php

use App\Jobs\LiveChat\ReleaseStaleHumanRequestsJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\Workspace;

function staleConversation(array $convOverrides = []): Conversation
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    return Conversation::factory()->create(array_merge([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ], $convOverrides));
}

test('clears stale unclaimed human_requested_at flags', function () {
    $stale = staleConversation([
        'human_requested_at' => now()->subMinutes(10),
        'claimed_by_user_id' => null,
    ]);

    (new ReleaseStaleHumanRequestsJob)->handle();

    expect($stale->fresh()->human_requested_at)->toBeNull();
});

test('appends a closure note as an assistant message', function () {
    $stale = staleConversation([
        'human_requested_at' => now()->subMinutes(10),
        'claimed_by_user_id' => null,
    ]);

    (new ReleaseStaleHumanRequestsJob)->handle();

    $note = Message::query()
        ->where('conversation_id', $stale->id)
        ->where('role', 'assistant')
        ->where('model', 'system:auto-fallback')
        ->first();

    expect($note)->not->toBeNull();
    expect($note->content)->toContain('busy');
});

test('leaves fresh requests alone', function () {
    $fresh = staleConversation([
        'human_requested_at' => now()->subMinute(),
        'claimed_by_user_id' => null,
    ]);

    (new ReleaseStaleHumanRequestsJob)->handle();

    expect($fresh->fresh()->human_requested_at)->not->toBeNull();
    expect(Message::where('conversation_id', $fresh->id)->where('model', 'system:auto-fallback')->count())
        ->toBe(0);
});

test('leaves claimed conversations alone even if old', function () {
    $claimed = staleConversation([
        'human_requested_at' => now()->subHours(2),
        'claimed_by_user_id' => 1,
        'claimed_at' => now()->subHour(),
    ]);

    (new ReleaseStaleHumanRequestsJob)->handle();

    expect($claimed->fresh()->human_requested_at)->not->toBeNull();
    expect($claimed->fresh()->claimed_by_user_id)->toBe(1);
});
