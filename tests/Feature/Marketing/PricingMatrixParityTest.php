<?php

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public /pricing matrix exposes the canonical 10 feature rows', function () {
    Plan::factory()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price_cents' => 24900,
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
        'is_active' => true,
        'show_on_pricing_page' => true,
    ]);

    $response = $this->get('/pricing');
    $response->assertOk();

    $rows = collect($response->viewData('page')['props']['matrix'] ?? [])
        ->pluck('label')
        ->all();

    expect($rows)->toEqual([
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

test('public /pricing matrix covers every plan-feature key that admin /billing renders', function () {
    // The admin billing page (resources/js/pages/app/billing.tsx)
    // renders 10 rows: conversations, messages, agents, sources,
    // workflows, integrations, members, workspaces, api, branding.
    // The public matrix must surface the same 10 — buyer screenshot
    // 2026-05-25 caught two missing rows.
    Plan::factory()->create(['name' => 'Free', 'price_cents' => 0, 'is_active' => true, 'show_on_pricing_page' => true]);

    $rows = collect($this->get('/pricing')->viewData('page')['props']['matrix'] ?? []);

    $adminFacing = collect([
        'Monthly conversations',
        'AI messages per month',
        'Published agents',
        'Knowledge sources',
        'Workflows',
        'Integrations',
        'Workspace members',
        'Workspaces per owner',
        'API access',
        'Branding removed',
    ]);

    $publicLabels = $rows->pluck('label');

    expect($adminFacing->diff($publicLabels)->all())->toBe([]);
});

test('AI messages row renders 100k when plan has monthly_messages=100000', function () {
    Plan::factory()->create([
        'name' => 'Pro',
        'price_cents' => 24900,
        'monthly_messages' => 100000,
        'is_active' => true,
        'show_on_pricing_page' => true,
    ]);

    $matrix = $this->get('/pricing')->viewData('page')['props']['matrix'];
    $row = collect($matrix)->firstWhere('label', 'AI messages per month');

    expect($row['pro'])->toBe(number_format(100000));
});

test('AI messages row renders Unlimited when plan has monthly_messages=null', function () {
    Plan::factory()->create([
        'name' => 'Enterprise',
        'price_cents' => 99900,
        'monthly_messages' => null,
        'is_active' => true,
        'show_on_pricing_page' => true,
    ]);

    $matrix = $this->get('/pricing')->viewData('page')['props']['matrix'];
    $row = collect($matrix)->firstWhere('label', 'AI messages per month');

    expect($row['enterprise'])->toBe('Unlimited');
});

test('Workspaces per owner row renders Unlimited when plan workspaces_limit is null', function () {
    Plan::factory()->create([
        'name' => 'Pro',
        'price_cents' => 24900,
        'workspaces_limit' => null,
        'is_active' => true,
        'show_on_pricing_page' => true,
    ]);

    $matrix = $this->get('/pricing')->viewData('page')['props']['matrix'];
    $row = collect($matrix)->firstWhere('label', 'Workspaces per owner');

    expect($row['pro'])->toBe('Unlimited');
});
