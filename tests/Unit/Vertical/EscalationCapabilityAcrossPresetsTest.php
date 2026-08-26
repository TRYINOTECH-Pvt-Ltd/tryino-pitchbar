<?php

use App\Models\Agent;
use App\Services\Tools\ToolRegistry;
use App\Services\Vertical\Presets\DocumentationPreset;
use App\Services\Vertical\Presets\EcommercePreset;
use App\Services\Vertical\Presets\GenericPreset;
use App\Services\Vertical\Presets\HelpCenterPreset;
use App\Services\Vertical\Presets\InternalKbPreset;
use App\Services\Vertical\Presets\MarketingPreset;
use App\Services\Vertical\Presets\SaasPreset;
use App\Services\Vertical\VerticalPresetRegistry;

/**
 * Buyer report: visitor typed "connect me to a human" on a non-help_center
 * site and the escalation button never appeared. Root cause: only the
 * help_center preset exposed `ticket_escalation`, so the LLM tool was
 * gated off everywhere else.
 *
 * Fix locks that into the test matrix below — adding a new preset
 * without `ticket_escalation` will now fail CI.
 */
$presets = [
    'help_center' => new HelpCenterPreset,
    'marketing' => new MarketingPreset,
    'saas' => new SaasPreset,
    'ecommerce' => new EcommercePreset,
    'documentation' => new DocumentationPreset,
    'internal_kb' => new InternalKbPreset,
    'generic' => new GenericPreset,
];

foreach ($presets as $slug => $preset) {
    test("preset {$slug} exposes ticket_escalation capability", function () use ($preset) {
        expect($preset->capabilities())->toContain('ticket_escalation');
    });
}

test('ToolRegistry::forAgent returns escalate_to_human for every preset', function () {
    $registry = new ToolRegistry(new VerticalPresetRegistry);
    $verticals = [
        'help_center', 'marketing', 'saas', 'ecommerce',
        'documentation', 'internal_kb', 'generic',
    ];

    foreach ($verticals as $vertical) {
        $agent = new Agent;
        $agent->site_type = $vertical;
        $agent->vertical_overrides = null;

        $names = array_map(fn ($t) => $t->name(), $registry->forAgent($agent));

        expect($names)
            ->toContain('escalate_to_human')
            ->and($names)
            ->not
            ->toBe([])
            ->and($vertical)->toBeString();
    }
});

test('vertical_overrides can still remove ticket_escalation per agent', function () {
    $registry = new ToolRegistry(new VerticalPresetRegistry);

    $agent = new Agent;
    $agent->site_type = 'saas';
    // Operator opts out by narrowing enabled_tools.
    $agent->vertical_overrides = [
        'enabled_tools' => ['pricing_card'],
    ];

    $names = array_map(fn ($t) => $t->name(), $registry->forAgent($agent));
    expect($names)->not->toContain('escalate_to_human');
});
