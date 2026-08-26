<?php

use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Notifications\NewLeadCaptured;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Notification;

/**
 * End-to-end pipe: a real visitor POST to /api/v1/widget/leads has to
 * end with the workspace owner receiving NewLeadCaptured. The unit-level
 * RouteLeadJob test covers the job in isolation; this one closes the
 * loop so a regression that breaks the dispatch (route registration,
 * job queueing, sync-driver behaviour) gets caught in CI.
 */
test('widget lead capture HTTP → owner is emailed', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'name' => 'Shop Bot',
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conversation->id)['token'];

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/leads', [
            'email' => 'visitor@example.com',
            'name' => 'Visitor Vivi',
        ])
        ->assertOk();

    $lead = Lead::query()->where('agent_id', $agent->id)->firstOrFail();
    expect($lead->email)->toBe('visitor@example.com');

    Notification::assertSentTo($owner, NewLeadCaptured::class, function (NewLeadCaptured $n) use ($lead) {
        return $n->lead->is($lead);
    });
});

test('mail body honours app branding instead of hardcoding Pitchbar', function () {
    AppSetting::query()->updateOrCreate(['id' => 1], [
        'site_title' => 'AcmeBot Sales',
    ]);
    AppSetting::flushSingleton();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Acme Agent']);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'name' => 'Vito',
        'status' => 'new',
        'fields' => [],
    ]);

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $rendered = (string) (new NewLeadCaptured($lead))->toMail($owner)->render();

    expect($rendered)->toContain('AcmeBot Sales');
    expect($rendered)->not->toContain('Pitchbar · Sales AI for any website');
});
