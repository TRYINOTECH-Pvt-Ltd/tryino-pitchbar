<?php

use App\Models\Plan;
use App\Services\Billing\PlanFeatureMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('labels() returns the canonical 10 rows in declared order', function () {
    expect(PlanFeatureMatrix::labels())->toEqual([
        'Published agents',
        'Monthly conversations',
        'AI messages per month',
        'Workspace members',
        'Workspaces per owner',
        'Knowledge sources',
        'Workflows',
        'Integrations',
        'API access',
        'Branding removed',
    ]);
});

test('matrixFor() projects every plan into a per-row column', function () {
    $free = Plan::factory()->create([
        'name' => 'Free',
        'monthly_conversations' => 100,
        'monthly_messages' => 500,
        'agents_limit' => 1,
        'sources_limit' => 2,
        'workflows_limit' => 1,
        'integrations_limit' => 0,
        'members_limit' => 2,
        'workspaces_limit' => 1,
        'api_access' => false,
        'features' => ['remove_branding' => false],
    ]);
    $pro = Plan::factory()->create([
        'name' => 'Pro',
        'monthly_conversations' => 3000,
        'monthly_messages' => 100000,
        'agents_limit' => null,
        'sources_limit' => null,
        'workflows_limit' => null,
        'integrations_limit' => null,
        'members_limit' => null,
        'workspaces_limit' => null,
        'api_access' => true,
        'features' => ['remove_branding' => true],
    ]);

    $rows = PlanFeatureMatrix::matrixFor(collect([$free, $pro]));

    expect($rows)->toHaveCount(10);

    $conversations = collect($rows)->firstWhere('label', 'Monthly conversations');
    expect($conversations['free'])->toBe(number_format(100));
    expect($conversations['pro'])->toBe(number_format(3000));

    $messages = collect($rows)->firstWhere('label', 'AI messages per month');
    expect($messages['free'])->toBe(number_format(500));
    expect($messages['pro'])->toBe(number_format(100000));

    $workspaces = collect($rows)->firstWhere('label', 'Workspaces per owner');
    expect($workspaces['free'])->toBe(number_format(1));
    expect($workspaces['pro'])->toBe('Unlimited');

    $api = collect($rows)->firstWhere('label', 'API access');
    expect($api['free'])->toBeFalse();
    expect($api['pro'])->toBeTrue();

    $branding = collect($rows)->firstWhere('label', 'Branding removed');
    expect($branding['free'])->toBeFalse();
    expect($branding['pro'])->toBeTrue();
});

test('matrixFor() renders 0 as em-dash, null as Unlimited', function () {
    $plan = Plan::factory()->create([
        'name' => 'Edge',
        'integrations_limit' => 0,
        'agents_limit' => null,
    ]);

    $rows = collect(PlanFeatureMatrix::matrixFor(collect([$plan])));

    expect($rows->firstWhere('label', 'Integrations')['edge'])->toBe('—');
    expect($rows->firstWhere('label', 'Published agents')['edge'])->toBe('Unlimited');
});

test('plan key is slugified for use as React column header', function () {
    $plan = Plan::factory()->create(['name' => 'Lifetime Deal']);

    $rows = PlanFeatureMatrix::matrixFor(collect([$plan]));

    expect($rows[0])->toHaveKey('lifetime_deal');
});
