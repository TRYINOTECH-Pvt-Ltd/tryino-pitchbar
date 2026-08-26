<?php

use App\Jobs\Analytics\RecomputeLeadScoreJob;
use App\Jobs\Rag\PersistTurnJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\VisitorPageView;
use App\Models\Workspace;
use App\Services\Experiments\ExperimentResolver;
use App\Services\Scoring\LeadScoringEngine;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

test('PersistTurnJob dispatches a RecomputeLeadScoreJob after persisting the turn', function () {
    Bus::fake([RecomputeLeadScoreJob::class]);

    $agent = Agent::factory()->create();
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'message_count' => 0,
    ]);

    $job = new PersistTurnJob(
        conversationId: $conversation->id,
        userMessageId: (string) Str::uuid(),
        userMessageText: 'hi',
        assistantMessageId: (string) Str::uuid(),
        assistantMessageText: 'hello',
        citations: [],
        confidence: 0.9,
        latencyMs: 120,
        model: 'fake-model',
    );

    $job->handle(app(ExperimentResolver::class));

    Bus::assertDispatched(RecomputeLeadScoreJob::class, fn (RecomputeLeadScoreJob $j) => $j->conversationId === $conversation->id);
});

test('RecomputeLeadScoreJob updates conversation score and bucket', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'message_count' => 8,
    ]);

    VisitorPageView::create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'conversation_id' => $conversation->id,
        'url' => 'https://example.com/pricing',
        'viewed_at' => now(),
    ]);

    (new RecomputeLeadScoreJob($conversation->id))->handle(app(LeadScoringEngine::class));

    $conversation->refresh();
    expect($conversation->lead_score)->toBeGreaterThan(0);
    expect($conversation->lead_score_bucket)->toBeIn(['low', 'medium', 'high']);
    expect($conversation->lead_score_updated_at)->not->toBeNull();
});

test('RecomputeLeadScoreJob handles a missing conversation gracefully', function () {
    (new RecomputeLeadScoreJob('not-a-real-id'))->handle(app(LeadScoringEngine::class));
})->throwsNoExceptions();
