<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * Belt-and-braces guard: the marketing demo widget loader (`<script
 * src=".../widget/widget.js">`) must NOT render on any authenticated
 * admin / customer surface, even on routes that today happen to use
 * the `welcome` Inertia component. A signed-in workspace owner
 * sitting on the dashboard should never see their own marketing
 * site's demo bot watching them.
 */
beforeEach(function () {
    // The MarketingDemoAgent resolver caches its lookup; we need a
    // fresh resolution per test since each test seeds a new agent.
    Cache::forget('marketing.demo_agent_id');

    // DEMO env gate. The marketing demo widget only mounts when
    // config('demo.enabled') is true — same flag that surfaces the
    // seeded demo credentials on /login. Production deployments
    // explicitly opt in.
    config()->set('demo.enabled', true);

    // Stand up the conventional demo workspace + a published agent
    // so MarketingDemoAgent::id() resolves to a real id.
    $workspace = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://shop.example.com'],
    ]);
});

test('marketing demo widget loader renders for unauthenticated visitors on /', function () {
    $response = $this->get('/');
    $response->assertOk();
    expect($response->getContent())->toContain('/widget/widget.js?v=');
});

test('marketing demo widget loader does NOT render for authenticated users', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/');
    $response->assertOk();
    // Auth check on the layout suppresses the script tag — even on /
    // (which IS the marketing welcome page) — once the user is signed in.
    expect($response->getContent())->not->toContain('/widget/widget.js?v=');
});
