<?php

use App\Jobs\Leads\RouteLeadJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\VisitorPageView;
use App\Models\WebhookSubscription;
use App\Models\Workspace;
use App\Services\Integrations\SlackPusher;
use App\Services\Webhooks\SignedDispatcher;
use Illuminate\Support\Facades\Notification;

test('lead.captured webhook payload contains score, bucket, and trajectory', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'lead_score' => 65,
        'lead_score_bucket' => 'medium',
        'message_count' => 5,
    ]);

    foreach (['https://example.com/home', 'https://example.com/pricing', 'https://example.com/demo'] as $i => $url) {
        VisitorPageView::create([
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'visitor_id' => $visitor->id,
            'conversation_id' => $conversation->id,
            'url' => $url,
            'viewed_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://hooks.example.com/leads',
        'secret' => 'shh',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $captured = null;
    $dispatcher = Mockery::mock(SignedDispatcher::class);
    $dispatcher->shouldReceive('send')->once()
        ->withArgs(function (string $url, string $secret, array $payload) use (&$captured) {
            if ($url === 'https://hooks.example.com/leads') {
                $captured = $payload;

                return true;
            }

            return false;
        })
        ->andReturn(true);

    (new RouteLeadJob($lead->id))->handle($dispatcher, app(SlackPusher::class));

    expect($captured)->not->toBeNull();
    expect($captured['event'])->toBe('lead.captured');
    expect($captured['score'])->toBe(65);
    expect($captured['score_bucket'])->toBe('medium');
    expect($captured['trajectory'])->toBeArray();
    expect(count($captured['trajectory']))->toBe(3);
    expect($captured['trajectory'][0]['url'])->toBe('https://example.com/demo');
});

test('lead.captured webhook trajectory is capped at 10 most recent views', function () {
    Notification::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    foreach (range(0, 24) as $i) {
        VisitorPageView::create([
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'visitor_id' => $visitor->id,
            'conversation_id' => $conversation->id,
            'url' => "https://example.com/page-$i",
            'viewed_at' => now()->subMinutes(30 - $i),
        ]);
    }

    $lead = Lead::create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'email' => 'visitor@example.com',
        'status' => 'new',
        'fields' => [],
    ]);

    WebhookSubscription::create([
        'workspace_id' => $workspace->id,
        'url' => 'https://hooks.example.com/leads',
        'secret' => 'shh',
        'events' => ['lead.captured'],
        'enabled' => true,
    ]);

    $captured = null;
    $dispatcher = Mockery::mock(SignedDispatcher::class);
    $dispatcher->shouldReceive('send')->once()
        ->withArgs(function (string $u, string $s, array $payload) use (&$captured) {
            $captured = $payload;

            return true;
        })
        ->andReturn(true);

    (new RouteLeadJob($lead->id))->handle($dispatcher, app(SlackPusher::class));

    expect(count($captured['trajectory']))->toBe(10);
});
