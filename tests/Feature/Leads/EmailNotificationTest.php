<?php

use App\Jobs\Leads\RouteLeadJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Notifications\NewLeadCaptured;
use App\Services\Integrations\SlackPusher;
use App\Services\Webhooks\SignedDispatcher;
use App\Support\AppBranding;
use Illuminate\Support\Facades\Notification;

function leadFor(Workspace $workspace): Lead
{
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'My Agent']);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    return Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'name' => 'Vito',
        'status' => 'new',
        'fields' => [],
    ]);
}

function pivotMember(Workspace $ws, User $user, string $role, bool $accepted = true): void
{
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => $accepted ? now() : null,
    ]);
}

test('owners and admins are notified on lead capture, not viewers', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $viewer = User::factory()->create();
    pivotMember($workspace, $owner, 'owner');
    pivotMember($workspace, $admin, 'admin');
    pivotMember($workspace, $viewer, 'viewer');

    $lead = leadFor($workspace);

    (new RouteLeadJob($lead->id))->handle(
        app(SignedDispatcher::class),
        app(SlackPusher::class),
    );

    Notification::assertSentTo($owner, NewLeadCaptured::class);
    Notification::assertSentTo($admin, NewLeadCaptured::class);
    Notification::assertNotSentTo($viewer, NewLeadCaptured::class);
});

test('un-accepted invitees are not notified', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create();
    $pending = User::factory()->create();
    pivotMember($workspace, $owner, 'owner');
    pivotMember($workspace, $pending, 'admin', accepted: false);

    $lead = leadFor($workspace);

    (new RouteLeadJob($lead->id))->handle(
        app(SignedDispatcher::class),
        app(SlackPusher::class),
    );

    Notification::assertSentTo($owner, NewLeadCaptured::class);
    Notification::assertNotSentTo($pending, NewLeadCaptured::class);
});

test('mail body contains the visitor email and a link to the inbox', function () {
    $lead = leadFor(Workspace::factory()->create());
    $owner = User::factory()->create(['email' => 'owner@example.com']);

    $mailable = (new NewLeadCaptured($lead))->toMail($owner);

    expect($mailable->subject)->toContain('visitor@example.com');
    $rendered = (string) $mailable->render();

    expect($rendered)->toContain('visitor@example.com');
    expect($rendered)->toContain('/app/inbox/'.$lead->id);
    // Branded chrome
    expect($rendered)->toContain('New lead captured');
    // Footer reflects whatever site_title the install carries — the
    // default unconfigured AppSetting falls back to 'Pitchbar'. The
    // WidgetLeadEndToEndEmailTest::'mail body honours app branding'
    // case proves the swap when the installer renames it.
    expect($rendered)->toContain(AppBranding::siteTitle());
});

test('routed_to is updated to record that email was sent', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create();
    pivotMember($workspace, $owner, 'owner');

    $lead = leadFor($workspace);

    (new RouteLeadJob($lead->id))->handle(
        app(SignedDispatcher::class),
        app(SlackPusher::class),
    );

    expect($lead->fresh()->routed_to)->toContain('email');
});
