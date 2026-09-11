<?php

use App\Enums\PlatformRole;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('self-host first signup becomes super_admin and can open onboarding and system settings', function () {
    config(['app.self_host' => true]);

    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ])->assertRedirect();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->role)->toBe(PlatformRole::SuperAdmin);

    $this->actingAs($user)
        ->get('/onboarding')
        ->assertOk();

    $this->actingAs($user)
        ->get('/settings/system')
        ->assertOk();
});

test('self-host later signups stay customers', function () {
    config(['app.self_host' => true]);

    User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->post('/register', [
        'name' => 'Charles Babbage',
        'email' => 'charles@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ])->assertRedirect();

    $user = User::where('email', 'charles@example.com')->firstOrFail();
    expect($user->role)->toBe(PlatformRole::Customer);

    $this->actingAs($user)
        ->get('/settings/system')
        ->assertNotFound();
});

test('hosted saas first signup stays a customer', function () {
    config(['app.self_host' => false]);

    $this->post('/register', [
        'name' => 'Hosted User',
        'email' => 'hosted@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ])->assertRedirect();

    $user = User::where('email', 'hosted@example.com')->firstOrFail();
    expect($user->role)->toBe(PlatformRole::Customer);
});
