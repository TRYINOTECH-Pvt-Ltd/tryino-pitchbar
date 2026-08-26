<?php

use App\Models\AppSetting;
use App\Models\Workspace;
use App\Services\Widget\WidgetDefaultsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = new WidgetDefaultsResolver;
});

test('returns hardcoded ship defaults when workspace + platform are empty', function () {
    $ws = Workspace::factory()->create(['widget_defaults' => null]);

    $result = $this->resolver->for($ws);

    expect($result['theme']['primary'])->toBe('#111827');
    expect($result['theme']['accent'])->toBe('#10b981');
    expect($result['persona']['name'])->toBe('Assistant');
    expect($result['persona']['tone'])->toBe('friendly');
    expect($result['guardrails']['max_chars'])->toBe(2500);
    expect($result['starter_prompts'])->toBe([]);
});

test('platform defaults override ship defaults', function () {
    AppSetting::singleton()->forceFill([
        'widget_defaults' => [
            'theme' => ['primary' => '#ff0000'],
            'persona' => ['tone' => 'expert'],
        ],
    ])->save();
    $ws = Workspace::factory()->create(['widget_defaults' => null]);

    $result = $this->resolver->for($ws);

    expect($result['theme']['primary'])->toBe('#ff0000');
    expect($result['theme']['accent'])->toBe('#10b981');
    expect($result['persona']['tone'])->toBe('expert');
    expect($result['persona']['name'])->toBe('Assistant');
});

test('workspace defaults override platform + ship defaults', function () {
    AppSetting::singleton()->forceFill([
        'widget_defaults' => [
            'theme' => ['primary' => '#ff0000'],
        ],
    ])->save();
    $ws = Workspace::factory()->create([
        'widget_defaults' => [
            'theme' => ['primary' => '#0000ff', 'radius' => 24],
            'persona' => ['name' => 'Custom Bot'],
        ],
    ]);

    $result = $this->resolver->for($ws);

    expect($result['theme']['primary'])->toBe('#0000ff');
    expect($result['theme']['radius'])->toBe(24);
    expect($result['theme']['accent'])->toBe('#10b981');
    expect($result['persona']['name'])->toBe('Custom Bot');
    expect($result['persona']['tone'])->toBe('friendly');
});

test('starter prompts pick the most-specific non-empty source', function () {
    AppSetting::singleton()->forceFill([
        'widget_defaults' => [
            'starter_prompts' => ['Platform A', 'Platform B'],
        ],
    ])->save();

    // Workspace empty → use platform.
    $ws1 = Workspace::factory()->create(['widget_defaults' => null]);
    expect($this->resolver->for($ws1)['starter_prompts'])
        ->toBe(['Platform A', 'Platform B']);

    // Workspace overrides → use workspace.
    $ws2 = Workspace::factory()->create([
        'widget_defaults' => [
            'starter_prompts' => ['WS only'],
        ],
    ]);
    expect($this->resolver->for($ws2)['starter_prompts'])->toBe(['WS only']);

    // Workspace empty array → falls through to platform.
    $ws3 = Workspace::factory()->create([
        'widget_defaults' => [
            'starter_prompts' => [],
        ],
    ]);
    expect($this->resolver->for($ws3)['starter_prompts'])
        ->toBe(['Platform A', 'Platform B']);
});

test('platform() helper returns platform-merged-on-ship defaults', function () {
    AppSetting::singleton()->forceFill([
        'widget_defaults' => [
            'theme' => ['primary' => '#abcdef'],
        ],
    ])->save();

    $result = $this->resolver->platform();

    expect($result['theme']['primary'])->toBe('#abcdef');
    expect($result['theme']['accent'])->toBe('#10b981');
    expect($result['persona']['name'])->toBe('Assistant');
});
