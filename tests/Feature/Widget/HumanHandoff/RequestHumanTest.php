<?php

use App\Events\Conversations\HumanRequestedEvent;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Event;

function makeRequestHumanConv(array $convOverrides = [], bool $withActiveOperator = true): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(array_merge([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ], $convOverrides));

    // Phase 2: routing flags the conversation only when at least one
    // operator is online. Default the test to "operator present" so
    // the queued path fires; tests that need the offline branch can
    // override.
    if ($withActiveOperator) {
        $user = User::factory()->create([
            'live_chat_available' => true,
            'last_active_at' => now(),
        ]);
        WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt['token']];
}

test('first call sets human_requested_at and broadcasts the event', function () {
    Event::fake([HumanRequestedEvent::class]);
    ['conv' => $conv, 'jwt' => $jwt] = makeRequestHumanConv();

    expect($conv->fresh()->human_requested_at)->toBeNull();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human');

    $response->assertOk();
    expect($conv->fresh()->human_requested_at)->not->toBeNull();
    Event::assertDispatched(HumanRequestedEvent::class, function ($event) use ($conv) {
        return $event->conversationId === $conv->id;
    });
});

test('second call within the waiting window is idempotent — no re-broadcast', function () {
    ['conv' => $conv, 'jwt' => $jwt] = makeRequestHumanConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human')
        ->assertOk();

    $firstStamp = $conv->fresh()->human_requested_at;
    expect($firstStamp)->not->toBeNull();

    Event::fake([HumanRequestedEvent::class]);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human')
        ->assertOk();

    expect($conv->fresh()->human_requested_at?->toIso8601String())
        ->toBe($firstStamp->toIso8601String());
    Event::assertNotDispatched(HumanRequestedEvent::class);
});

test('claimed conversations do not re-flag human_requested_at', function () {
    // Operator already claimed → request-human should silently no-op,
    // not reset the timestamp or re-broadcast.
    Event::fake([HumanRequestedEvent::class]);
    ['conv' => $conv, 'jwt' => $jwt] = makeRequestHumanConv([
        'claimed_by_user_id' => 1,
        'claimed_at' => now(),
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human')
        ->assertOk();

    expect($conv->fresh()->human_requested_at)->toBeNull();
    Event::assertNotDispatched(HumanRequestedEvent::class);
});

test('missing token → 401', function () {
    $this->postJson('/api/v1/widget/request-human')->assertStatus(401);
});

test('invalid token → 401', function () {
    $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
        ->postJson('/api/v1/widget/request-human')
        ->assertStatus(401);
});
