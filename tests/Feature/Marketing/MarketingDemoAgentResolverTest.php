<?php

use App\Models\Agent;
use App\Models\Workspace;
use App\Support\MarketingDemoAgent;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('returns the env-pinned agent id when it exists and is published', function () {
    $ws = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $ws->id,
    ]);

    config()->set('services.marketing.demo_agent_id', $agent->id);

    expect(MarketingDemoAgent::id())->toBe($agent->id);
});

test('falls back to auto-discovery when env-pinned id is stale (agent deleted)', function () {
    $ws = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    $freshAgent = Agent::factory()->published()->create([
        'workspace_id' => $ws->id,
    ]);

    config()->set('services.marketing.demo_agent_id', '019e0000-0000-7000-0000-000000000000');

    expect(MarketingDemoAgent::id())->toBe($freshAgent->id);
});

test('falls back to auto-discovery when env-pinned agent is unpublished', function () {
    $ws = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    $unpublished = Agent::factory()->create([
        'workspace_id' => $ws->id,
        'is_published' => false,
    ]);
    $published = Agent::factory()->published()->create([
        'workspace_id' => $ws->id,
    ]);

    config()->set('services.marketing.demo_agent_id', $unpublished->id);

    expect(MarketingDemoAgent::id())->toBe($published->id);
});

test('returns null when no demo workspace and env unset', function () {
    config()->set('services.marketing.demo_agent_id', null);

    expect(MarketingDemoAgent::id())->toBeNull();
});

test('returns null when env unset and demo workspace has no published agent', function () {
    Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    config()->set('services.marketing.demo_agent_id', null);

    expect(MarketingDemoAgent::id())->toBeNull();
});
