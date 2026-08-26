<?php

use App\Events\Conversations\HumanRequestedEvent;
use App\Jobs\LiveChat\NotifyOperatorsHumanRequestedJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Widget\WidgetJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function routeMakeConv(array $convOverrides = [], array $workspaceOverrides = []): array
{
    $workspace = Workspace::factory()->create($workspaceOverrides);
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(array_merge([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ], $convOverrides));
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['workspace' => $workspace, 'conv' => $conv, 'jwt' => $jwt['token']];
}

function routeOperator(Workspace $workspace, array $userOverrides = []): User
{
    $user = User::factory()->create(array_merge([
        'live_chat_available' => true,
        'last_active_at' => now(),
    ], $userOverrides));
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $user;
}

test('queued status when an operator is online and within business hours', function () {
    // Scope the fakes to the specific event/job we care about — a
    // blanket Event::fake() also intercepts Eloquent model events
    // (including the HasUuidV7 trait's `creating` listener) which
    // breaks every factory in this file.
    Bus::fake([NotifyOperatorsHumanRequestedJob::class]);
    Event::fake([HumanRequestedEvent::class]);
    ['workspace' => $workspace, 'conv' => $conv, 'jwt' => $jwt] = routeMakeConv();
    routeOperator($workspace);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human');
    $response->assertOk();

    expect($response->json('data.status'))->toBe('queued');
    expect($response->json('data.wait_timeout_seconds'))->toBe(120);
    expect($conv->fresh()->human_requested_at)->not->toBeNull();
    Bus::assertDispatched(NotifyOperatorsHumanRequestedJob::class);
    Event::assertDispatched(HumanRequestedEvent::class);
});

test('still queues + notifies even when zero operators are available', function () {
    // Contract: ALWAYS queue + ALWAYS notify so operators see the
    // request when they next open the inbox. Widget falls back to the
    // offline banner locally after a 120s wait.
    // Scope the fakes to the specific event/job we care about — a
    // blanket Event::fake() also intercepts Eloquent model events
    // (including the HasUuidV7 trait's `creating` listener) which
    // breaks every factory in this file.
    Bus::fake([NotifyOperatorsHumanRequestedJob::class]);
    Event::fake([HumanRequestedEvent::class]);
    ['workspace' => $workspace, 'conv' => $conv, 'jwt' => $jwt] = routeMakeConv();
    // Member exists but is opted out, so presence=0.
    routeOperator($workspace, ['live_chat_available' => false]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human');
    $response->assertOk();

    expect($response->json('data.status'))->toBe('queued');
    expect($response->json('data.wait_timeout_seconds'))->toBe(120);
    expect($response->json('data.active_operator_count'))->toBe(0);
    // Crucial: human_requested_at IS now set even with 0 operators,
    // so the dashboard's "needs human" queue surfaces this request
    // for any operator who logs in within the wait window.
    expect($conv->fresh()->human_requested_at)->not->toBeNull();
    Bus::assertDispatched(NotifyOperatorsHumanRequestedJob::class);
    Event::assertDispatched(HumanRequestedEvent::class);
});

test('offline_after_hours when business hours configured + currently closed', function () {
    // Scope the fakes to the specific event/job we care about — a
    // blanket Event::fake() also intercepts Eloquent model events
    // (including the HasUuidV7 trait's `creating` listener) which
    // breaks every factory in this file.
    Bus::fake([NotifyOperatorsHumanRequestedJob::class]);
    Event::fake([HumanRequestedEvent::class]);
    ['workspace' => $workspace, 'conv' => $conv, 'jwt' => $jwt] = routeMakeConv(
        [],
        [
            'business_hours' => [
                'enabled' => true,
                'timezone' => 'UTC',
                'schedule' => [
                    'monday' => [],
                    'tuesday' => [],
                    'wednesday' => [],
                    'thursday' => [],
                    'friday' => [],
                    'saturday' => [],
                    'sunday' => [],
                ],
            ],
        ],
    );
    routeOperator($workspace);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human');
    $response->assertOk();

    expect($response->json('data.status'))->toBe('offline_after_hours');
    expect($response->json('data.wait_timeout_seconds'))->toBe(0);
    // Even after-hours we still queue the request + notify operators,
    // because some operators DO take chats outside their official
    // hours and a real signal beats a silent drop.
    expect($conv->fresh()->human_requested_at)->not->toBeNull();
    Bus::assertDispatched(NotifyOperatorsHumanRequestedJob::class);
    Event::assertDispatched(HumanRequestedEvent::class);
});

test('queued + flagged when business_hours is null (always-open default)', function () {
    // Scope the fakes to the specific event/job we care about — a
    // blanket Event::fake() also intercepts Eloquent model events
    // (including the HasUuidV7 trait's `creating` listener) which
    // breaks every factory in this file.
    Bus::fake([NotifyOperatorsHumanRequestedJob::class]);
    Event::fake([HumanRequestedEvent::class]);
    ['workspace' => $workspace, 'conv' => $conv, 'jwt' => $jwt] = routeMakeConv();
    routeOperator($workspace);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human');
    $response->assertOk();

    expect($response->json('data.status'))->toBe('queued');
    expect($conv->fresh()->human_requested_at)->not->toBeNull();
    Bus::assertDispatched(NotifyOperatorsHumanRequestedJob::class);
});

test('second click within the same waiting window is idempotent (no double notify)', function () {
    // Scope the fakes to the specific event/job we care about — a
    // blanket Event::fake() also intercepts Eloquent model events
    // (including the HasUuidV7 trait's `creating` listener) which
    // breaks every factory in this file.
    Bus::fake([NotifyOperatorsHumanRequestedJob::class]);
    Event::fake([HumanRequestedEvent::class]);
    ['workspace' => $workspace, 'conv' => $conv, 'jwt' => $jwt] = routeMakeConv();
    routeOperator($workspace);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human')
        ->assertOk();
    $firstTimestamp = $conv->fresh()->human_requested_at;

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/request-human')
        ->assertOk();

    expect($conv->fresh()->human_requested_at?->toIso8601String())
        ->toBe($firstTimestamp?->toIso8601String());
    Bus::assertDispatchedTimes(NotifyOperatorsHumanRequestedJob::class, 1);
    Event::assertDispatchedTimes(HumanRequestedEvent::class, 1);
});
