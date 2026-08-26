<?php

use App\Enums\PlatformRole;
use App\Models\User;

test('a customer cannot reach any /admin/* route — gets 404 (not 403, to hide existence)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $routes = [
        '/admin',
        '/admin/workspaces',
        '/admin/users',
        '/admin/agents',
        '/admin/conversations',
        '/admin/leads',
        '/admin/usage',
    ];

    foreach ($routes as $route) {
        $response = $this->actingAs($customer)->get($route);
        expect($response->getStatusCode())->toBe(
            404,
            "Route {$route} should 404 for customers but returned {$response->getStatusCode()}"
        );
    }
});

test('an unauthenticated visitor is redirected to login from /admin (not 404)', function () {
    // Auth middleware fires first, redirecting to login. The 404-vs-403 hide
    // only matters once authenticated.
    $response = $this->get('/admin');
    expect($response->getStatusCode())->toBe(302);
});

test('a super_admin can reach /admin', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)->get('/admin')->assertOk();
});

test('User::isSuperAdmin returns the right boolean', function () {
    expect(User::factory()->create(['role' => PlatformRole::SuperAdmin])->isSuperAdmin())->toBeTrue();
    expect(User::factory()->create(['role' => PlatformRole::Customer])->isSuperAdmin())->toBeFalse();
    expect(User::factory()->create()->isSuperAdmin())->toBeFalse(); // default = customer
});

test('pitchbar:make-admin promotes and demotes', function () {
    $user = User::factory()->create(['email' => 'staff@example.com']);
    expect($user->isSuperAdmin())->toBeFalse();

    $this->artisan('pitchbar:make-admin', ['email' => 'staff@example.com'])->assertSuccessful();
    expect($user->fresh()->isSuperAdmin())->toBeTrue();

    $this->artisan('pitchbar:make-admin', ['email' => 'staff@example.com', '--demote' => true])->assertSuccessful();
    expect($user->fresh()->isSuperAdmin())->toBeFalse();
});
