<?php

use App\Jobs\Crawl\CrawlPageJob;
use App\Models\Agent;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Bus;

test('init returns a token + conversation for a published agent on an allowed origin', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    $response = $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['conversation_id', 'visitor_id', 'jwt', 'expires_at', 'agent', 'reverb'],
        ]);

    $token = $response->json('data.jwt');
    $claims = app(WidgetJwt::class)->verify($token);
    expect($claims['agent_id'])->toBe($agent->id);
});

test('init refuses an unpublished agent', function () {
    $agent = Agent::factory()->create(['is_published' => false]);

    $this->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertStatus(404);
});

test('init refuses a disallowed origin', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://example.com'],
    ]);

    $this->withHeaders(['Origin' => 'https://malicious.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertStatus(403);
});

test('init rejects a subdomain when only the parent domain is allowed', function () {
    // Strict, exact-match contract: listing thecodestudio.com must NOT
    // implicitly permit pitchbar.thecodestudio.com. The widget script is
    // public; subdomains owned by other teams (or attackers via DNS) must
    // not inherit trust from the parent.
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://thecodestudio.com'],
    ]);

    $this->withHeaders(['Origin' => 'https://pitchbar.thecodestudio.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertStatus(403);
});

test('init accepts a subdomain only when it is explicitly listed', function () {
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://pitchbar.thecodestudio.com'],
    ]);

    $this->withHeaders(['Origin' => 'https://pitchbar.thecodestudio.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk();
});

test('init tolerates trailing slashes / whitespace / mixed case in allowed_origins', function () {
    // Real-world: customer pastes "https://acme.com/" or " https://ACME.com "
    // and the strict in_array check used to reject the browser's canonical
    // "https://acme.com" Origin header. Both sides are now normalised so
    // these all match.
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => [
            'https://acme.com/',
            '  https://Caps.example.com  ',
        ],
    ]);

    $this->withHeaders(['Origin' => 'https://acme.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk();

    $this->withHeaders(['Origin' => 'https://caps.example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk();
});

test('init denies all when allowed_origins is empty (deny-by-default)', function () {
    // Empty allowed_origins used to allow-all — that made the widget
    // script public-by-default, which defeats the whole point of the
    // origin gate. Now an empty list must lock the agent out everywhere
    // until the customer explicitly lists the origins they own.
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => [],
    ]);

    $this->withHeaders(['Origin' => 'https://anywhere.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertStatus(403);
});

test('init kicks off auto-index for the visited page when conditions match', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://shop.example.com'],
        'auto_index_visited_pages' => true,
    ]);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'page_url' => 'https://shop.example.com/products/macbook',
        ])
        ->assertOk();

    Bus::assertDispatched(CrawlPageJob::class);
});

test('init returns the full agent.theme object so widget can read primary/accent/radius/position', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'theme' => [
            'primary' => '#ff5733',
            'accent' => '#1abc9c',
            'radius' => 8,
            'position' => 'bottom-right',
            'launcher_label' => 'Need help?',
        ],
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.agent.theme.primary', '#ff5733')
        ->assertJsonPath('data.agent.theme.accent', '#1abc9c')
        ->assertJsonPath('data.agent.theme.radius', 8)
        ->assertJsonPath('data.agent.theme.position', 'bottom-right')
        ->assertJsonPath('data.agent.theme.launcher_label', 'Need help?');
});

test('init exposes starter_prompts so the widget can render them as chips', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
        'starter_prompts' => [
            'What is your pricing?',
            'Do you have a free plan?',
            'Can I talk to a human?',
        ],
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.agent.starter_prompts', [
            'What is your pricing?',
            'Do you have a free plan?',
            'Can I talk to a human?',
        ]);
});

test('init returns null starter_prompts when none are configured', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.agent.starter_prompts', null);
});

test('init shows branding for free plan workspaces with config-driven label and url', function () {
    config([
        'branding.label' => 'Powered by Pitchbar',
        'branding.url' => 'https://pitchbar.thecodestudio.xyz',
        'branding.footer_logo_path' => 'branding/footer/logo.png',
    ]);

    $free = Plan::query()->updateOrCreate(
        ['slug' => 'free'],
        ['name' => 'Free', 'monthly_conversations' => 100, 'price_cents' => 0,
            'features' => ['remove_branding' => false], 'is_active' => true],
    );
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.branding.show', true)
        ->assertJsonPath('data.branding.label', 'Powered by Pitchbar')
        ->assertJsonPath('data.branding.url', 'https://pitchbar.thecodestudio.xyz')
        ->assertJsonPath('data.branding.display_mode', 'logo_only')
        ->assertJsonPath('data.branding.logo_url', fn ($value) => is_string($value)
            && str_contains((string) $value, 'branding/footer/logo.png'));
});

test('init hides branding for paid plan workspaces', function () {
    $pro = Plan::query()->updateOrCreate(
        ['slug' => 'pro'],
        ['name' => 'Pro', 'monthly_conversations' => 3000, 'price_cents' => 24900,
            'features' => ['remove_branding' => true], 'is_active' => true],
    );
    $workspace = Workspace::factory()->create(['plan_id' => $pro->id]);
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://example.com'],
    ]);

    $this->withHeaders(['Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/init', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('data.branding.show', false);
});

test('init does not auto-index when the agent has the toggle off', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'allowed_origins' => ['https://shop.example.com'],
        'auto_index_visited_pages' => false,
    ]);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])
        ->postJson('/api/v1/widget/init', [
            'agent_id' => $agent->id,
            'page_url' => 'https://shop.example.com/products/macbook',
        ])
        ->assertOk();

    Bus::assertNotDispatched(CrawlPageJob::class);
});
