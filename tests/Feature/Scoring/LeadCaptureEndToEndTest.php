<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\VisitorPageView;
use App\Models\WebhookSubscription;
use App\Models\Workspace;
use App\Services\Webhooks\SignedDispatcher;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withHeaders(['Origin' => 'https://example.com']);
});

test('end-to-end: capturing a lead recomputes the score and the webhook receives the up-to-date score + trajectory', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'message_count' => 6,
        'lead_score' => 0,
        'lead_score_bucket' => 'low',
    ]);

    foreach (['/home', '/pricing', '/demo'] as $i => $path) {
        VisitorPageView::create([
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'visitor_id' => $visitor->id,
            'conversation_id' => $conversation->id,
            'url' => 'https://example.com'.$path,
            'title' => 'Page'.$path,
            'viewed_at' => now()->subMinutes(10 - $i),
        ]);
    }

    WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://hooks.example.com/leads',
        'secret' => 'shh-secret',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $captured = null;
    $dispatcher = Mockery::mock(SignedDispatcher::class);
    $dispatcher->shouldReceive('send')
        ->once()
        ->withArgs(function (string $url, string $secret, array $payload) use (&$captured) {
            if ($url === 'https://hooks.example.com/leads' && $secret === 'shh-secret') {
                $captured = $payload;

                return true;
            }

            return false;
        })
        ->andReturn(true);
    $this->app->instance(SignedDispatcher::class, $dispatcher);

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conversation->id);

    $this->withHeaders([
        'Origin' => 'https://example.com',
        'Authorization' => 'Bearer '.$jwt['token'],
    ])->postJson('/api/v1/widget/leads', [
        'email' => 'buyer@example.com',
        'name' => 'Buyer',
    ])->assertOk();

    // Conversation should be updated by RecomputeLeadScoreJob::dispatchSync
    // BEFORE the webhook fires.
    $conversation->refresh();
    expect($conversation->lead_score)->toBeGreaterThan(0);
    expect($conversation->lead_score_bucket)->toBeIn(['low', 'medium', 'high']);
    expect($conversation->lead_score_reasons)->toBeArray();
    expect(count($conversation->lead_score_reasons))->toBeGreaterThan(0);

    // The webhook payload should reflect the post-capture score (not 0).
    expect($captured)->not->toBeNull();
    expect($captured['event'])->toBe('lead.captured');
    expect($captured['score'])->toBe($conversation->lead_score);
    expect($captured['score_bucket'])->toBe($conversation->lead_score_bucket);
    expect($captured['trajectory'])->toBeArray();
    expect(count($captured['trajectory']))->toBe(3);
    // Trajectory comes back newest-first
    expect($captured['trajectory'][0]['url'])->toBe('https://example.com/demo');

    // Webhook payload should include the lead-captured +15 weight because
    // the recompute ran AFTER the Lead row was inserted.
    expect($conversation->lead_score)->toBeGreaterThanOrEqual(15);
});

test('end-to-end: lead capture without prior page views still produces a score from chat engagement alone', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'message_count' => 4,
    ]);

    $dispatcher = Mockery::mock(SignedDispatcher::class);
    $dispatcher->shouldNotReceive('send'); // No subscriptions configured.
    $this->app->instance(SignedDispatcher::class, $dispatcher);

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conversation->id);

    $this->withHeaders([
        'Origin' => 'https://example.com',
        'Authorization' => 'Bearer '.$jwt['token'],
    ])->postJson('/api/v1/widget/leads', [
        'email' => 'visitor@example.com',
    ])->assertOk();

    $conversation->refresh();
    // engagement: 4 * 2 = 8, lead_captured: 15 → total 23
    expect($conversation->lead_score)->toBe(23);
    expect(Lead::query()->withoutGlobalScopes()->where('email', 'visitor@example.com')->exists())->toBeTrue();
});
