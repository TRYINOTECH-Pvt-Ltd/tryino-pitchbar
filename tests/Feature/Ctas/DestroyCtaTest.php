<?php

use App\Models\CtaRule;

test('owner can delete a CTA and is redirected to the agent ctas page', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $cta = CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'book_demo',
        'label' => 'Book a demo',
        'kind' => 'demo',
        'conditions' => [],
        'target' => ['url' => 'https://example.com/demo'],
        'enabled' => true,
        'priority' => 100,
    ]);

    $this->actingAs($user)
        ->from(route('agents.ctas.index', ['agent' => $agent->id]))
        ->delete(route('cta.destroy', ['ctaRule' => $cta->id]))
        ->assertRedirect(route('agents.ctas.index', ['agent' => $agent->id]));

    $this->assertDatabaseMissing('cta_rules', ['id' => $cta->id]);
});

test('viewer cannot delete a CTA', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'viewer']);

    $cta = CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'book_demo',
        'label' => 'Book a demo',
        'kind' => 'demo',
        'conditions' => [],
        'target' => ['url' => 'https://example.com/demo'],
        'enabled' => true,
        'priority' => 100,
    ]);

    $this->actingAs($user)
        ->delete(route('cta.destroy', ['ctaRule' => $cta->id]))
        ->assertForbidden();

    $this->assertDatabaseHas('cta_rules', ['id' => $cta->id]);
});

test('cross-workspace CTA delete is blocked', function () {
    ['user' => $userA] = workspaceMemberWithAgent(['role' => 'owner']);
    ['agent' => $agentB] = workspaceMemberWithAgent(['role' => 'owner']);

    $cta = CtaRule::create([
        'agent_id' => $agentB->id,
        'name' => 'book_demo',
        'label' => 'Book a demo',
        'kind' => 'demo',
        'conditions' => [],
        'target' => ['url' => 'https://example.com/demo'],
        'enabled' => true,
        'priority' => 100,
    ]);

    $response = $this->actingAs($userA)
        ->delete(route('cta.destroy', ['ctaRule' => $cta->id]));

    expect($response->status())->toBeIn([403, 404]);
    $this->assertDatabaseHas('cta_rules', ['id' => $cta->id]);
});

test('Inertia delete returns redirect with correct Location header', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $cta = CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'book_consultation',
        'label' => 'Book a Consultation',
        'kind' => 'demo',
        'conditions' => [],
        'target' => ['url' => 'https://www.porifyx.com/#booking'],
        'enabled' => true,
        'priority' => 100,
    ]);

    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer' => route('agents.ctas.index', ['agent' => $agent->id]),
        ])
        ->from(route('agents.ctas.index', ['agent' => $agent->id]))
        ->delete(route('cta.destroy', ['ctaRule' => $cta->id]));

    expect($response->status())->toBeIn([302, 303]);
    expect($response->headers->get('Location'))
        ->toBe(route('agents.ctas.index', ['agent' => $agent->id]));

    $this->assertDatabaseMissing('cta_rules', ['id' => $cta->id]);
});

test('redirect target ctas page renders after CTA deletion', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $cta = CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'only_cta',
        'label' => 'Only one',
        'kind' => 'demo',
        'conditions' => [],
        'target' => ['url' => 'https://example.com/demo'],
        'enabled' => true,
        'priority' => 100,
    ]);

    $this->actingAs($user)
        ->delete(route('cta.destroy', ['ctaRule' => $cta->id]));

    $this->actingAs($user)
        ->get(route('agents.ctas.index', ['agent' => $agent->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/agents/ctas'));
});
