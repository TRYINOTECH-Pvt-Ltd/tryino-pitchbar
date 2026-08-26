<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('workspace owner can view their widget settings page', function () {
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)
        ->get('/settings/widget')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/widget')
            ->where('scope', 'workspace')
            ->has('effective.theme.primary')
            ->has('effective.persona.tone'));
});

test('PATCH /settings/widget saves the workspace defaults', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember();

    $this->actingAs($user)
        ->patch('/settings/widget', [
            'theme' => ['primary' => '#1234ab', 'accent' => '#abcdef'],
            'persona' => ['tone' => 'expert', 'name' => 'My Bot'],
            'guardrails' => ['max_chars' => 1500],
            'starter_prompts' => ['Hello?', 'Pricing?'],
        ])
        ->assertRedirect();

    $workspace->refresh();
    expect($workspace->widget_defaults['theme']['primary'])->toBe('#1234ab');
    expect($workspace->widget_defaults['persona']['tone'])->toBe('expert');
    expect($workspace->widget_defaults['starter_prompts'])->toBe(['Hello?', 'Pricing?']);
});

test('PATCH /settings/widget rejects an out-of-range max_chars', function () {
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)
        ->patch('/settings/widget', [
            'guardrails' => ['max_chars' => 50],
        ])
        ->assertSessionHasErrors('guardrails.max_chars');
});

test('PATCH /settings/widget rejects an invalid hex colour', function () {
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)
        ->patch('/settings/widget', [
            'theme' => ['primary' => 'not-a-colour'],
        ])
        ->assertSessionHasErrors('theme.primary');
});

test('apply-to-all updates every agent in the workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember();
    $a = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'theme' => ['primary' => '#000000'],
    ]);
    $b = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'theme' => ['primary' => '#000000'],
    ]);

    $workspace->forceFill([
        'widget_defaults' => [
            'theme' => ['primary' => '#ff00ff', 'accent' => '#10b981', 'radius' => 12],
            'persona' => ['name' => 'Pushed', 'tone' => 'concise'],
            'guardrails' => ['avoid' => [], 'max_chars' => 1200],
            'starter_prompts' => ['Why hello'],
        ],
    ])->save();

    $this->actingAs($user)
        ->post('/settings/widget/apply-to-all')
        ->assertRedirect();

    $a->refresh();
    $b->refresh();
    expect($a->theme['primary'])->toBe('#ff00ff');
    expect($a->persona['name'])->toBe('Pushed');
    expect($a->guardrails['max_chars'])->toBe(1200);
    expect($a->starter_prompts)->toBe(['Why hello']);
    expect($b->theme['primary'])->toBe('#ff00ff');
});

test('non-super-admin cannot reach platform-wide widget defaults page', function () {
    ['user' => $user] = workspaceMember();

    $this->actingAs($user)
        ->get('/settings/widget-defaults')
        ->assertStatus(404);
});

test('super-admin can save platform-wide widget defaults to AppSetting', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/widget-defaults', [
            'theme' => ['primary' => '#abc123'],
            'persona' => ['tone' => 'expert'],
        ])
        ->assertRedirect();

    AppSetting::flushSingleton();
    $row = AppSetting::singleton()->fresh();
    expect($row->widget_defaults['theme']['primary'])->toBe('#abc123');
    expect($row->widget_defaults['persona']['tone'])->toBe('expert');
});

test('a new agent created via AgentController inherits workspace defaults', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember();
    $workspace->forceFill([
        'widget_defaults' => [
            'theme' => ['primary' => '#777777'],
            'persona' => ['name' => 'Inherited'],
            'starter_prompts' => ['First chip'],
        ],
    ])->save();

    $this->actingAs($user)
        ->post('/app/agents', [
            'name' => 'Brand new',
            'language_default' => 'en',
        ])
        ->assertRedirect();

    $agent = Agent::query()
        ->where('workspace_id', $workspace->id)
        ->where('name', 'Brand new')
        ->firstOrFail();
    expect($agent->theme['primary'])->toBe('#777777');
    expect($agent->persona['name'])->toBe('Inherited');
    expect($agent->starter_prompts)->toBe(['First chip']);
});

test('the visitor widget /init endpoint returns the inherited workspace defaults', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember();
    $workspace->forceFill([
        'widget_defaults' => [
            'theme' => [
                'primary' => '#abc123',
                'accent' => '#def456',
                'launcher_label' => 'Custom launcher',
            ],
            'persona' => ['name' => 'Bobby Bot', 'tone' => 'concise'],
            'guardrails' => ['max_chars' => 1800],
            'starter_prompts' => ['What\'s on sale?', 'Where is my order?'],
        ],
    ])->save();

    // Create the new agent — it should inherit on store.
    $this->actingAs($user)
        ->post('/app/agents', [
            'name' => 'Inheritance probe',
            'language_default' => 'en',
        ])
        ->assertRedirect();

    $agent = Agent::query()
        ->where('workspace_id', $workspace->id)
        ->where('name', 'Inheritance probe')
        ->firstOrFail();

    // Mark the agent published + open origin so /init succeeds for the
    // visitor JWT issuance (mirrors a real ecommerce embed).
    $agent->forceFill([
        'is_published' => true,
        'allowed_origins' => ['https://example.com'],
    ])->save();

    // Hit the public /widget/init exactly as a visitor would.
    $response = $this->postJson(
        '/api/v1/widget/init',
        ['agent_id' => $agent->id, 'page_url' => 'https://example.com/'],
        ['Origin' => 'https://example.com'],
    );

    $response->assertOk();
    $payload = $response->json('data.agent');

    expect($payload['theme']['primary'])->toBe('#abc123');
    expect($payload['theme']['accent'])->toBe('#def456');
    expect($payload['theme']['launcher_label'])->toBe('Custom launcher');
    expect($payload['persona']['name'])->toBe('Bobby Bot');
    expect($payload['persona']['tone'])->toBe('concise');
    expect($payload['starter_prompts'])->toBe([
        'What\'s on sale?',
        'Where is my order?',
    ]);
});
