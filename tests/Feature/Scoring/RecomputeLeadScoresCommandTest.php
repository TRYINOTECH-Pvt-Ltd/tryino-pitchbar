<?php

use App\Jobs\Analytics\RecomputeLeadScoreJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\VisitorPageView;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

test('the command recomputes lead score inline for all matching conversations', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'message_count' => 5,
        'is_playground' => false,
        'lead_score' => 0,
        'lead_score_bucket' => 'low',
    ]);
    VisitorPageView::create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'conversation_id' => $conversation->id,
        'url' => 'https://example.com/pricing',
        'viewed_at' => now(),
    ]);

    $this->artisan('pitchbar:recompute-lead-scores')
        ->expectsOutputToContain('Recomputing lead score')
        ->assertExitCode(0);

    $conversation->refresh();
    expect($conversation->lead_score)->toBeGreaterThan(0);
    expect($conversation->lead_score_updated_at)->not->toBeNull();
});

test('the command skips playground conversations', function () {
    Bus::fake();
    $agent = Agent::factory()->create();
    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => Visitor::factory()->create(['agent_id' => $agent->id])->id,
        'is_playground' => true,
    ]);

    $this->artisan('pitchbar:recompute-lead-scores', ['--queue' => true])
        ->expectsOutputToContain('nothing to do')
        ->assertExitCode(0);

    Bus::assertNotDispatched(RecomputeLeadScoreJob::class);
});

test('the command can restrict to a single agent', function () {
    Bus::fake();
    $agentA = Agent::factory()->create();
    $agentB = Agent::factory()->create();
    Conversation::factory()->create([
        'agent_id' => $agentA->id,
        'visitor_id' => Visitor::factory()->create(['agent_id' => $agentA->id])->id,
        'is_playground' => false,
    ]);
    Conversation::factory()->create([
        'agent_id' => $agentB->id,
        'visitor_id' => Visitor::factory()->create(['agent_id' => $agentB->id])->id,
        'is_playground' => false,
    ]);

    $this->artisan('pitchbar:recompute-lead-scores', ['--queue' => true, '--agent' => $agentA->id])
        ->expectsOutputToContain('Dispatched 1')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(RecomputeLeadScoreJob::class, 1);
});

test('the command can restrict to a single workspace', function () {
    Bus::fake();
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();
    $agentA = Agent::factory()->create(['workspace_id' => $wsA->id]);
    $agentB = Agent::factory()->create(['workspace_id' => $wsB->id]);
    Conversation::factory()->create([
        'agent_id' => $agentA->id,
        'visitor_id' => Visitor::factory()->create(['agent_id' => $agentA->id])->id,
        'is_playground' => false,
    ]);
    Conversation::factory()->create([
        'agent_id' => $agentB->id,
        'visitor_id' => Visitor::factory()->create(['agent_id' => $agentB->id])->id,
        'is_playground' => false,
    ]);

    $this->artisan('pitchbar:recompute-lead-scores', ['--queue' => true, '--workspace' => $wsA->id])
        ->expectsOutputToContain('Dispatched 1')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(RecomputeLeadScoreJob::class, 1);
});

test('the command reports zero work when nothing matches', function () {
    $this->artisan('pitchbar:recompute-lead-scores', ['--workspace' => '00000000-0000-0000-0000-000000000000'])
        ->expectsOutputToContain('nothing to do')
        ->assertExitCode(0);
});
