<?php

use App\Models\AppSetting;
use App\Support\AppBranding;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('home renders for an unauthenticated visitor', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->where('canRegister', true)
        ->where('demoAgentId', null)
        ->where('content.brand_name', 'Pitchbar')
        ->where('content.hero.line_one', 'Turn every')
    );
});

test('home ships testimonial + faq sections out of the box (#15)', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('content.testimonials.items', 5, fn (Assert $item) => $item
            ->has('name')
            ->has('role')
            ->has('company')
            ->has('quote')
            ->etc()
        )
        ->has('content.faq.items', 10, fn (Assert $item) => $item
            ->has('question')
            ->has('answer')
        )
    );
});

test('admin-edited testimonials/faq override the defaults (#15)', function () {
    AppSetting::singleton()->forceFill([
        'marketing_home_content' => [
            'testimonials' => [
                'title' => 'Real teams say it best',
                'items' => [
                    [
                        'name' => 'Custom Customer',
                        'role' => 'Founder',
                        'company' => 'Acme Co',
                        'quote' => 'It is the best thing since sliced bread.',
                    ],
                ],
            ],
            'faq' => [
                'items' => [
                    [
                        'question' => 'Custom q?',
                        'answer' => 'Custom a.',
                    ],
                ],
            ],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('content.testimonials.title', 'Real teams say it best')
        ->where('content.testimonials.items.0.name', 'Custom Customer')
        ->where('content.testimonials.items.0.quote', 'It is the best thing since sliced bread.')
        ->where('content.faq.items.0.question', 'Custom q?')
        ->where('content.faq.items.0.answer', 'Custom a.')
    );
});

test('home renders admin-managed marketing content when stored', function () {
    AppSetting::singleton()->forceFill([
        'marketing_home_content' => [
            'hero' => [
                'line_one' => 'Convert every',
                'line_three_highlight' => 'buyer',
            ],
            'nav_items' => [
                [
                    'label' => 'Overview',
                    'href' => '/',
                ],
            ],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('content.hero.line_one', 'Convert every')
        ->where('content.hero.line_three_highlight', 'buyer')
        ->where('content.hero.line_four_highlight', 'conversation.')
        ->where('content.nav_items.0.label', 'Overview')
        ->where('content.brand_name', 'Pitchbar')
    );
});

test('home shares the dynamic site branding payload when custom branding is stored', function () {
    Storage::fake(AppBranding::disk());

    AppSetting::singleton()->forceFill([
        'site_title' => 'Acme Assist',
        'header_logo_path' => 'branding/header/logo.png',
        'footer_logo_path' => 'branding/footer/logo.png',
        'favicon_path' => 'branding/favicon/favicon.png',
        'header_brand_display' => 'logo_text',
        'footer_brand_display' => 'text_only',
        'dashboard_brand_display' => 'logo_only',
    ])->save();
    AppSetting::flushSingleton();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('branding.site_title', 'Acme Assist')
            ->where('branding.header_brand_display', 'logo_text')
            ->where('branding.footer_brand_display', 'text_only')
            ->where('branding.dashboard_brand_display', 'logo_only')
            ->where('branding.header_logo_url', fn ($value) => is_string($value)
                && str_contains((string) $value, 'branding/header/logo.png'))
            ->where('branding.footer_logo_url', fn ($value) => is_string($value)
                && str_contains((string) $value, 'branding/footer/logo.png'))
            ->where('branding.favicon_url', fn ($value) => is_string($value)
                && str_contains((string) $value, 'branding/favicon/favicon.png'))
        );
});

test('demo widget script is rendered only when MARKETING_DEMO_AGENT_ID is set AND demo mode is enabled', function () {
    // Baseline: no agent id, no demo mode → no script.
    config()->set('services.marketing.demo_agent_id', null);
    config()->set('demo.enabled', false);
    $this->get('/')
        ->assertDontSee('/widget/widget.js')
        ->assertInertia(fn (Assert $page) => $page->where('demoAgentId', null));

    // MarketingDemoAgent resolves env-pinned ids via DB lookup (so a
    // stale id from a prior seed doesn't make the live widget 404).
    // Create a real published agent and use its id below.
    \Illuminate\Support\Facades\Cache::flush();
    $ws = \App\Models\Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    $agent = \App\Models\Agent::factory()->published()->create([
        'workspace_id' => $ws->id,
    ]);
    $agentId = $agent->id;

    // Agent id set BUT demo gate off → still no script. The gate is
    // explicit so a production deployment that accidentally leaves
    // MARKETING_DEMO_AGENT_ID populated never injects a chat widget
    // for real visitors.
    config()->set('services.marketing.demo_agent_id', $agentId);
    config()->set('demo.enabled', false);
    $this->get('/')
        ->assertDontSee('/widget/widget.js')
        ->assertInertia(fn (Assert $page) => $page->where('demoAgentId', $agentId));

    // Agent id set AND demo gate on → script renders.
    config()->set('demo.enabled', true);
    $response = $this->get('/');
    $response->assertOk();
    $response->assertSee('/widget/widget.js', false);
    $response->assertSee('data-agent-id="'.$agentId.'"', false);
    $response->assertInertia(fn (Assert $page) => $page->where('demoAgentId', $agentId));
});

test('pricing and how-it-works render', function () {
    $this->get('/pricing')->assertOk();
    $this->get('/how-it-works')->assertOk();
});

test('privacy page renders admin-managed privacy content', function () {
    AppSetting::singleton()->forceFill([
        'privacy_policy_content' => [
            'title' => 'Customer privacy promise',
            'summary' => 'How we handle visitor conversations and routed lead data.',
            'contact' => [
                'email' => 'privacy@acme.example',
            ],
            'gdpr' => [
                'request_instructions' => 'Include the workspace slug and the visitor email when you request export or deletion.',
            ],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Customer privacy promise')
        ->assertSee('privacy@acme.example')
        ->assertSee('Include the workspace slug and the visitor email when you request export or deletion.');
});

test('authenticated visitors still receive the marketing landing page component', function () {
    $bag = workspaceMember();

    $this->actingAs($bag['user'])
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', true)
        );
});

test('marketing/start sets session domain and redirects to register', function () {
    $response = $this->post('/marketing/start', ['domain' => 'https://example.com']);

    $response->assertRedirectContains('/register');
    expect(session('marketing.start_domain'))->toBe('https://example.com');
});
