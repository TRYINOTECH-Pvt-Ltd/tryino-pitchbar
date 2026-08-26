<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\CtaRule;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Triggers\CtaSelector;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('returns the highest-priority enabled rule that matches', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://example.com/pricing',
    ]);

    CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'low priority generic',
        'label' => 'Generic CTA',
        'kind' => 'link',
        'priority' => 1,
        'enabled' => true,
        'target' => ['url' => 'https://x.com/low'],
    ]);
    CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'pricing-page demo',
        'label' => 'Book a demo',
        'kind' => 'demo',
        'priority' => 100,
        'enabled' => true,
        'conditions' => ['url_contains' => '/pricing'],
        'target' => ['url' => 'https://x.com/demo'],
    ]);

    $cta = app(CtaSelector::class)->select($conv, 'Sure — happy to help with pricing!');

    expect($cta)->not->toBeNull();
    expect($cta['kind'])->toBe('demo');
    expect($cta['url'])->toBe('https://x.com/demo');
});

test('returns null when no rules match', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://example.com/blog',
    ]);

    CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'pricing only',
        'label' => 'Book a demo',
        'kind' => 'demo',
        'priority' => 100,
        'enabled' => true,
        'conditions' => ['url_contains' => '/pricing'],
        'target' => ['url' => 'https://x.com/demo'],
    ]);

    expect(app(CtaSelector::class)->select($conv, 'whatever'))->toBeNull();
});

test('selectAll returns every matching rule in priority order (capped at MAX_CTAS)', function () {
    // Regression guard for the buyer's "I added 3 CTAs but only one shows up"
    // complaint. select() was already designed to return one CTA; selectAll()
    // is the new multi-card surface that the widget actually renders.
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://example.com/pricing',
    ]);

    foreach (range(1, 5) as $i) {
        CtaRule::create([
            'agent_id' => $agent->id,
            'name' => 'rule '.$i,
            'label' => 'CTA '.$i,
            'kind' => 'link',
            'priority' => 100 - $i,
            'enabled' => true,
            'target' => ['url' => 'https://x.com/'.$i],
        ]);
    }

    $picks = app(CtaSelector::class)->selectAll($conv, 'whatever');

    expect($picks)->toHaveCount(CtaSelector::MAX_CTAS);
    expect($picks[0]['label'])->toBe('CTA 1');
    expect($picks[1]['label'])->toBe('CTA 2');
    expect($picks[2]['label'])->toBe('CTA 3');
});

test('selectAll skips rules whose conditions do not match the visitor context', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://example.com/blog',
    ]);

    CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'pricing only',
        'label' => 'Pricing CTA',
        'kind' => 'link',
        'priority' => 100,
        'enabled' => true,
        'conditions' => ['url_contains' => '/pricing'],
        'target' => ['url' => 'https://x.com/pricing'],
    ]);
    CtaRule::create([
        'agent_id' => $agent->id,
        'name' => 'always',
        'label' => 'Always CTA',
        'kind' => 'link',
        'priority' => 1,
        'enabled' => true,
        'target' => ['url' => 'https://x.com/any'],
    ]);

    $picks = app(CtaSelector::class)->selectAll($conv, 'whatever');

    expect($picks)->toHaveCount(1);
    expect($picks[0]['label'])->toBe('Always CTA');
});
