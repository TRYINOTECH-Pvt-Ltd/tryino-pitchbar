<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\IntegrationCardsContent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function platformAdmin(): User
{
    $user = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    return $user;
}

test('defaults() ships exactly the 4 native integration cards', function () {
    $defaults = IntegrationCardsContent::defaults();

    expect($defaults)->toHaveCount(4);
    expect(array_column($defaults, 'name'))
        ->toEqual(['Notion', 'Google Docs', 'Slack', 'Stripe']);
});

test('resolve() returns defaults verbatim when no override is set', function () {
    expect(IntegrationCardsContent::resolve())->toEqual(IntegrationCardsContent::defaults());
});

test('resolve() overlays admin overrides on top of defaults by name', function () {
    AppSetting::singleton()->forceFill([
        'integration_cards' => [
            [
                'key' => 'stripe',
                'name' => 'Stripe (Custom)',
                'category' => 'Payments',
                'tagline' => 'Custom tagline',
                'description' => 'Custom description',
            ],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $cards = IntegrationCardsContent::resolve();
    $stripe = collect($cards)->firstWhere('icon', 'CreditCard');

    expect($stripe['name'])->toBe('Stripe (Custom)');
    expect($stripe['category'])->toBe('Payments');
    expect($stripe['tagline'])->toBe('Custom tagline');
    expect($stripe['description'])->toBe('Custom description');
    // Icon / accent stay controlled by the codebase even when overridden.
    expect($stripe['icon'])->toBe('CreditCard');
    expect($stripe['accent'])->toBe('indigo');
});

test('resolve() ignores empty override fields and falls back per-field', function () {
    AppSetting::singleton()->forceFill([
        'integration_cards' => [
            [
                'key' => 'stripe',
                'name' => 'Stripe (Custom)',
                'category' => '',
                'tagline' => '   ',
                'description' => '',
            ],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $stripe = collect(IntegrationCardsContent::resolve())
        ->firstWhere('icon', 'CreditCard');
    $default = collect(IntegrationCardsContent::defaults())
        ->firstWhere('icon', 'CreditCard');

    expect($stripe['name'])->toBe('Stripe (Custom)');
    expect($stripe['category'])->toBe($default['category']);
    expect($stripe['tagline'])->toBe($default['tagline']);
    expect($stripe['description'])->toBe($default['description']);
});

test('resolve() discards override rows whose key matches no default card', function () {
    AppSetting::singleton()->forceFill([
        'integration_cards' => [
            ['key' => 'unknown', 'name' => 'Foo', 'category' => 'X', 'tagline' => 'Y', 'description' => 'Z'],
        ],
    ])->save();
    AppSetting::flushSingleton();

    expect(IntegrationCardsContent::resolve())->toEqual(IntegrationCardsContent::defaults());
});

test('normalise() strips anonymous rows and keeps the 4 defaults order', function () {
    $rows = IntegrationCardsContent::normalise([
        ['key' => 'stripe', 'name' => 'Stripe!', 'category' => 'Billing', 'tagline' => 't', 'description' => 'd'],
        ['key' => 'mystery', 'name' => 'Mystery', 'category' => 'x', 'tagline' => 'x', 'description' => 'x'],
        ['key' => 'notion', 'name' => 'Notion', 'category' => 'KB', 'tagline' => 't', 'description' => 'd'],
    ]);

    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'key'))->toEqual(['stripe', 'notion']);
});

test('public /integrations renders the overridden Stripe card copy', function () {
    AppSetting::singleton()->forceFill([
        'integration_cards' => [
            [
                'key' => 'stripe',
                'name' => 'Stripe by Acme',
                'category' => 'Acme billing',
                'tagline' => 'Acme tagline',
                'description' => 'Acme description text.',
            ],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $response = $this->get('/integrations');

    $response->assertOk();
    $native = collect($response->viewData('page')['props']['native'] ?? []);
    $stripe = $native->firstWhere('icon', 'CreditCard');

    expect($stripe['name'])->toBe('Stripe by Acme');
    expect($stripe['description'])->toBe('Acme description text.');
});

test('super_admin can patch integration card copy via /settings/system/integrations', function () {
    $admin = platformAdmin();

    $response = $this->actingAs($admin)
        ->patch('/settings/system/integrations', [
            'integration_cards' => [
                [
                    'key' => 'stripe',
                    'name' => 'Stripe (Test)',
                    'category' => 'Payments',
                    'tagline' => 'New tagline',
                    'description' => 'New description',
                ],
            ],
        ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $persisted = AppSetting::singleton()->integration_cards;
    expect($persisted)->toBeArray();
    $stripeRow = collect($persisted)->firstWhere('key', 'stripe');
    expect($stripeRow['name'])->toBe('Stripe (Test)');
    expect($stripeRow['description'])->toBe('New description');
});

test('customer-role user cannot patch integration card copy', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer]);
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    $response = $this->actingAs($user)
        ->patch('/settings/system/integrations', [
            'integration_cards' => [
                ['key' => 'stripe', 'name' => 'Hack', 'category' => 'x', 'tagline' => 'x', 'description' => 'x'],
            ],
        ]);

    expect($response->status())->toBeIn([302, 403, 404]);
});

test('GET /settings/system?section=integrations prefills the form with current cards', function () {
    $admin = platformAdmin();
    AppSetting::singleton()->forceFill([
        'integration_cards' => [
            [
                'key' => 'stripe',
                'name' => 'Stripe Pro',
                'category' => 'Billing',
                'tagline' => 'Pro tagline',
                'description' => 'Pro description.',
            ],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $this->actingAs($admin)
        ->get('/settings/system')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/system')
            ->has('form.integration_cards', 4)
            ->where('form.integration_cards.3.key', 'stripe')
            ->where('form.integration_cards.3.name', 'Stripe Pro')
            ->where('form.integration_cards.3.description', 'Pro description.')
            ->where('form.integration_cards.0.key', 'notion')
            ->where('form.integration_cards.0.name', 'Notion'));
});

test('admin save → public render roundtrip via the real endpoints', function () {
    $admin = platformAdmin();

    // Step 1: admin patches new copy via the live PATCH endpoint.
    $this->actingAs($admin)
        ->patch('/settings/system/integrations', [
            'integration_cards' => [
                [
                    'key' => 'stripe',
                    'name' => 'Stripe Custom',
                    'category' => 'Billing',
                    'tagline' => 'Custom tagline',
                    'description' => 'Custom description text.',
                ],
            ],
        ])
        ->assertRedirect();

    // Step 2: re-fetch the admin form — fields prefilled with override.
    $this->actingAs($admin)
        ->get('/settings/system')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('form.integration_cards.3.key', 'stripe')
            ->where('form.integration_cards.3.name', 'Stripe Custom'));

    // Step 3: anon visit to public /integrations renders the override.
    auth()->logout();
    $this->get('/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('native.3.icon', 'CreditCard')
            ->where('native.3.name', 'Stripe Custom')
            ->where('native.3.description', 'Custom description text.'));
});

test('admin patching empty integration_cards clears overrides (reset to defaults)', function () {
    $admin = platformAdmin();
    AppSetting::singleton()->forceFill([
        'integration_cards' => [
            ['key' => 'stripe', 'name' => 'Custom', 'category' => 'Billing', 'tagline' => 't', 'description' => 'd'],
        ],
    ])->save();
    AppSetting::flushSingleton();

    $this->actingAs($admin)
        ->patch('/settings/system/integrations', ['integration_cards' => []])
        ->assertRedirect();

    expect(AppSetting::singleton()->integration_cards)->toBe([]);
    expect(IntegrationCardsContent::resolve())->toEqual(IntegrationCardsContent::defaults());
});
