<?php

use App\Jobs\LiveChat\NotifyOperatorsHumanRequestedJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Notifications\HumanRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function notifyHumanWorkspace(): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    return ['workspace' => $workspace, 'conv' => $conv];
}

function makeMember(Workspace $workspace, bool $available, bool $accepted = true): User
{
    $user = User::factory()->create([
        'live_chat_available' => $available,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => $accepted ? now() : null,
    ]);

    return $user;
}

test('notifies every workspace member with live_chat_available=true', function () {
    Notification::fake();
    ['workspace' => $workspace, 'conv' => $conv] = notifyHumanWorkspace();
    $on1 = makeMember($workspace, available: true);
    $on2 = makeMember($workspace, available: true);
    $off = makeMember($workspace, available: false);

    (new NotifyOperatorsHumanRequestedJob($conv->id, $workspace->id))->handle();

    Notification::assertSentTo($on1, HumanRequestedNotification::class);
    Notification::assertSentTo($on2, HumanRequestedNotification::class);
    Notification::assertNotSentTo($off, HumanRequestedNotification::class);
});

test('skips members whose invitations are not accepted', function () {
    Notification::fake();
    ['workspace' => $workspace, 'conv' => $conv] = notifyHumanWorkspace();
    $pending = makeMember($workspace, available: true, accepted: false);
    $active = makeMember($workspace, available: true);

    (new NotifyOperatorsHumanRequestedJob($conv->id, $workspace->id))->handle();

    Notification::assertSentTo($active, HumanRequestedNotification::class);
    Notification::assertNotSentTo($pending, HumanRequestedNotification::class);
});

test('no-op when no available operators exist', function () {
    Notification::fake();
    ['workspace' => $workspace, 'conv' => $conv] = notifyHumanWorkspace();
    makeMember($workspace, available: false);
    makeMember($workspace, available: false);

    (new NotifyOperatorsHumanRequestedJob($conv->id, $workspace->id))->handle();

    Notification::assertNothingSent();
});

test('no-op when the workspace no longer exists', function () {
    Notification::fake();

    (new NotifyOperatorsHumanRequestedJob('missing-conv', 'missing-workspace'))->handle();

    Notification::assertNothingSent();
});

test('database notification payload includes the conversation URL', function () {
    Notification::fake();
    ['workspace' => $workspace, 'conv' => $conv] = notifyHumanWorkspace();
    $member = makeMember($workspace, available: true);

    (new NotifyOperatorsHumanRequestedJob($conv->id, $workspace->id))->handle();

    Notification::assertSentTo(
        $member,
        HumanRequestedNotification::class,
        function (HumanRequestedNotification $n) use ($conv) {
            $payload = $n->toArray($n);

            return $payload['kind'] === 'human_requested'
                && $payload['conversation_id'] === $conv->id
                && str_contains((string) $payload['url'], $conv->id);
        },
    );
});
