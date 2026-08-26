<?php

use App\Jobs\Analytics\RecomputeLeadScoreJob;
use App\Models\Agent;
use App\Models\VisitorPageView;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    $this->withHeaders(['Origin' => 'https://example.com']);
});

test('init records a visitor page view when page_url is provided', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
        'page_url' => 'https://example.com/pricing',
        'page_title' => 'Pricing — Plans',
        'referrer' => 'https://google.com',
    ])->assertOk();

    expect(VisitorPageView::query()->withoutWorkspaceScope()->count())->toBe(1);

    $view = VisitorPageView::query()->withoutWorkspaceScope()->first();
    expect($view->workspace_id)->toBe($workspace->id);
    expect($view->agent_id)->toBe($agent->id);
    expect($view->url)->toBe('https://example.com/pricing');
    expect($view->title)->toBe('Pricing — Plans');
    expect($view->referrer)->toBe('https://google.com');

    Bus::assertDispatched(RecomputeLeadScoreJob::class);
});

test('init does not record a page view when page_url is missing', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://example.com'],
    ]);

    $this->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk();

    expect(VisitorPageView::query()->withoutWorkspaceScope()->count())->toBe(0);
    Bus::assertNotDispatched(RecomputeLeadScoreJob::class);
});

test('init dedupes same-page reloads inside the 2-minute window', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://example.com'],
    ]);

    $anonId = 'anon_'.bin2hex(random_bytes(8));

    $payload = [
        'agent_id' => $agent->id,
        'page_url' => 'https://example.com/home',
        'anon_id' => $anonId,
    ];

    $this->postJson('/api/v1/widget/init', $payload)->assertOk();
    $this->postJson('/api/v1/widget/init', $payload)->assertOk();
    $this->postJson('/api/v1/widget/init', $payload)->assertOk();

    expect(VisitorPageView::query()->withoutWorkspaceScope()->count())->toBe(1);
});

test('init records a new view when visitor navigates to a different page', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://example.com'],
    ]);

    $anonId = 'anon_'.bin2hex(random_bytes(8));

    $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
        'page_url' => 'https://example.com/home',
        'anon_id' => $anonId,
    ])->assertOk();

    $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agent->id,
        'page_url' => 'https://example.com/pricing',
        'anon_id' => $anonId,
    ])->assertOk();

    expect(VisitorPageView::query()->withoutWorkspaceScope()->count())->toBe(2);
});

test('cross-tenant page views are hidden by the workspace global scope', function () {
    $workspaceA = Workspace::factory()->create();
    $agentA = Agent::factory()->published()->create([
        'workspace_id' => $workspaceA->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agentA->id,
        'page_url' => 'https://example.com/a',
    ])->assertOk();

    $workspaceB = Workspace::factory()->create();
    $agentB = Agent::factory()->published()->create([
        'workspace_id' => $workspaceB->id,
        'allowed_origins' => ['https://example.com'],
    ]);
    $this->postJson('/api/v1/widget/init', [
        'agent_id' => $agentB->id,
        'page_url' => 'https://example.com/b',
    ])->assertOk();

    expect(VisitorPageView::query()->withoutWorkspaceScope()->count())->toBe(2);

    app(CurrentWorkspace::class)->set($workspaceA->id);
    expect(VisitorPageView::query()->count())->toBe(1);
});
