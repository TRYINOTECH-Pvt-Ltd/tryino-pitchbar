<?php

use App\Models\Agent;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\Tools\EscalateToHumanTool;
use App\Services\Vertical\VerticalPresetRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry(new VerticalPresetRegistry);
});

test('registry exposes the escalate_to_human tool', function () {
    $tool = $this->registry->get('escalate_to_human');
    expect($tool)->toBeInstanceOf(EscalateToHumanTool::class);
});

test('forAgent returns no tools when site_type is null', function () {
    $agent = new Agent;
    $agent->site_type = null;

    expect($this->registry->forAgent($agent))->toBe([]);
});

test('forAgent returns escalate_to_human for help_center agents (capability match)', function () {
    $agent = new Agent;
    $agent->site_type = 'help_center';
    $agent->vertical_overrides = null;

    $tools = $this->registry->forAgent($agent);
    $names = array_map(fn ($t) => $t->name(), $tools);
    // The help_center preset also exposes the ticketing + KB tools, but
    // escalate_to_human is the original surface this test was guarding —
    // assert presence rather than exact count so adding ticket_open /
    // send_kb_article doesn't regress us here.
    expect($names)->toContain('escalate_to_human');
});

test('forAgent surfaces lookup_order for ecommerce (order_status capability matches)', function () {
    // Ecommerce preset includes the `order_status` capability which
    // the LookupOrderTool gates on. Since v2.0.0 + the buyer-reported
    // human-handoff bug, escalate_to_human is also surfaced on every
    // preset — the test pins the order_status path without rejecting
    // the escalation tool that now travels alongside.
    $agent = new Agent;
    $agent->site_type = 'ecommerce';
    $agent->vertical_overrides = null;

    $tools = $this->registry->forAgent($agent);
    $names = array_map(fn ($t) => $t->name(), $tools);

    expect($names)->toContain('lookup_order');
    expect($names)->toContain('escalate_to_human');
});

test('vertical_overrides.capabilities replaces the preset capability set', function () {
    // `vertical_overrides.capabilities` is a REPLACEMENT, not a merge —
    // operators can pin the exact capability set they want. Verify by
    // overriding ecommerce (which normally includes order_status +
    // ticket_escalation) to expose just ticket_escalation.
    $agent = new Agent;
    $agent->site_type = 'ecommerce';
    $agent->vertical_overrides = [
        'capabilities' => ['ticket_escalation'],
    ];

    $tools = $this->registry->forAgent($agent);
    $names = array_map(fn ($t) => $t->name(), $tools);

    expect($names)->toContain('escalate_to_human');
    expect($names)->not->toContain('lookup_order');
});

test('vertical_overrides.enabled_tools narrows the allow-list', function () {
    $agent = new Agent;
    $agent->site_type = 'help_center';
    $agent->vertical_overrides = [
        'enabled_tools' => ['some_other_tool'],
    ];

    expect($this->registry->forAgent($agent))->toBe([]);
});

test('openAiToolsFor returns valid OpenAI tools shape', function () {
    $agent = new Agent;
    $agent->site_type = 'help_center';
    $agent->vertical_overrides = null;

    $payload = $this->registry->openAiToolsFor($agent);
    $names = array_map(fn ($t) => $t['function']['name'] ?? '', $payload);

    expect($payload)->not->toBeEmpty();
    expect($payload[0]['type'])->toBe('function');
    expect($payload[0]['function']['parameters'])->toBeArray();
    expect($names)->toContain('escalate_to_human');
});
