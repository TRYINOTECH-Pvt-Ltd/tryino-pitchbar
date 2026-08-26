<?php

use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\Workspace;
use App\Support\MarketingWidget;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('payload() returns disabled when no widget configured and demo is off', function () {
    config()->set('demo.enabled', false);

    $payload = MarketingWidget::payload(isAuthenticated: false);

    expect($payload['enabled'])->toBeFalse();
    expect($payload['agent_id'])->toBeNull();
    expect($payload['is_demo'])->toBeFalse();
});

test('payload() returns the operator-picked agent when widget is on and agent published', function () {
    config()->set('demo.enabled', false);
    $agent = Agent::factory()->published()->create();
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $agent->id,
    ])->save();
    AppSetting::flushSingleton();

    $payload = MarketingWidget::payload(isAuthenticated: false);

    expect($payload['enabled'])->toBeTrue();
    expect($payload['agent_id'])->toBe($agent->id);
    expect($payload['is_demo'])->toBeFalse();
});

test('payload() returns nothing when operator picked an unpublished agent (no silent demo substitution)', function () {
    config()->set('demo.enabled', true);
    $ws = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    Agent::factory()->published()->create(['workspace_id' => $ws->id]); // demo fallback exists
    $unpublished = Agent::factory()->create(['is_published' => false]);

    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $unpublished->id,
    ])->save();
    AppSetting::flushSingleton();

    $payload = MarketingWidget::payload(isAuthenticated: false);

    expect($payload['enabled'])->toBeFalse();
    expect($payload['agent_id'])->toBeNull();
});

test('payload() falls through to demo agent when widget off, demo on, visitor not authed', function () {
    config()->set('demo.enabled', true);
    $ws = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    $demoAgent = Agent::factory()->published()->create(['workspace_id' => $ws->id]);

    $payload = MarketingWidget::payload(isAuthenticated: false);

    expect($payload['enabled'])->toBeTrue();
    expect($payload['agent_id'])->toBe($demoAgent->id);
    expect($payload['is_demo'])->toBeTrue();
});

test('payload() never mounts the demo agent for an authenticated visitor', function () {
    config()->set('demo.enabled', true);
    $ws = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    Agent::factory()->published()->create(['workspace_id' => $ws->id]);

    $payload = MarketingWidget::payload(isAuthenticated: true);

    expect($payload['enabled'])->toBeFalse();
    expect($payload['agent_id'])->toBeNull();
});

test('marketingWidget shared prop reflects the current operator setting on a marketing page', function () {
    config()->set('demo.enabled', false);
    $agent = Agent::factory()->published()->create();
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $agent->id,
    ])->save();
    AppSetting::flushSingleton();

    $response = $this->get('/');

    $response->assertOk();
    $widget = $response->viewData('page')['props']['marketingWidget'] ?? null;

    expect($widget)->not->toBeNull();
    expect($widget['enabled'])->toBeTrue();
    expect($widget['agent_id'])->toBe($agent->id);
});

test('marketingWidget shared prop updates after a toggle (proves reactive contract)', function () {
    config()->set('demo.enabled', false);
    $agent = Agent::factory()->published()->create();
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $agent->id,
    ])->save();
    AppSetting::flushSingleton();

    $before = $this->get('/')->viewData('page')['props']['marketingWidget'];
    expect($before['enabled'])->toBeTrue();
    expect($before['agent_id'])->toBe($agent->id);

    // Operator toggles the widget off.
    AppSetting::singleton()->forceFill(['marketing_widget_enabled' => false])->save();
    AppSetting::flushSingleton();
    Cache::flush();

    $after = $this->get('/')->viewData('page')['props']['marketingWidget'];

    expect($after['enabled'])->toBeFalse();
    expect($after['agent_id'])->toBeNull();
});
