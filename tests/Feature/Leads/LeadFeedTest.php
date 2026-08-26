<?php

use App\Events\Leads\LeadCapturedEvent;
use App\Jobs\Leads\RouteLeadJob;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Services\Integrations\SlackPusher;
use App\Services\Webhooks\SignedDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

test('GET /app/leads/feed returns no leads when nothing has happened', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $response = $this->actingAs($user)->getJson('/app/leads/feed');
    $response->assertOk();
    $response->assertJsonPath('data.count_24h', 0);
    expect($response->json('data.leads'))->toBe([]);
});

test('GET /app/leads/feed returns only leads newer than ?since', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $convOld = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $convNew = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00'));
    $oldLead = Lead::create([
        'conversation_id' => $convOld->id,
        'agent_id' => $agent->id,
        'email' => 'old@example.com',
        'name' => 'Old Vito',
        'status' => 'new',
        'fields' => [],
    ]);
    $oldLead->forceFill(['created_at' => '2026-05-08 10:00:00'])->save();

    Carbon::setTestNow(Carbon::parse('2026-05-08 14:00:00'));
    $cursor = Carbon::parse('2026-05-08 13:00:00');
    $newLead = Lead::create([
        'conversation_id' => $convNew->id,
        'agent_id' => $agent->id,
        'email' => 'new@example.com',
        'name' => 'Fresh Vito',
        'status' => 'new',
        'fields' => [],
    ]);
    $newLead->forceFill(['created_at' => '2026-05-08 14:00:00'])->save();

    // The test wraps a frozen-clock scenario; reset so later tests
    // in the same process get the real wall clock back.
    Carbon::setTestNow();

    $response = $this->actingAs($user)->getJson(
        '/app/leads/feed?since='.urlencode($cursor->toIso8601String()),
    );
    $response->assertOk();

    $leads = $response->json('data.leads');
    expect($leads)->toHaveCount(1);
    expect($leads[0]['id'])->toBe($newLead->id);
    expect($leads[0]['email'])->toBe('new@example.com');
    expect($leads[0]['inbox_url'])->toBe('/app/inbox/'.$newLead->id);
});

test('cross-workspace leads are filtered out by the BelongsToWorkspace scope', function () {
    ['user' => $userA] = workspaceMemberWithAgent();
    ['agent' => $agentB] = workspaceMemberWithAgent();
    $visitor = Visitor::factory()->create(['agent_id' => $agentB->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agentB->id,
        'visitor_id' => $visitor->id,
    ]);
    Lead::create([
        'conversation_id' => $conv->id,
        'agent_id' => $agentB->id,
        'email' => 'other@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    $response = $this->actingAs($userA)->getJson('/app/leads/feed');
    expect($response->json('data.leads'))->toBe([]);
});

test('RouteLeadJob dispatches LeadCapturedEvent on the workspace channel', function () {
    Event::fake([LeadCapturedEvent::class]);

    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::create([
        'conversation_id' => $conv->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    (new RouteLeadJob($lead->id))->handle(
        app(SignedDispatcher::class),
        app(SlackPusher::class),
    );

    Event::assertDispatched(LeadCapturedEvent::class, function (LeadCapturedEvent $event) use ($workspace, $lead) {
        return $event->workspaceId === $workspace->id
            && $event->leadId === $lead->id
            && $event->email === 'visitor@example.com';
    });
});
