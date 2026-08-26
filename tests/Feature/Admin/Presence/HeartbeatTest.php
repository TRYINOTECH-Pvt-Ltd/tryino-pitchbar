<?php

use App\Models\User;

test('heartbeat bumps last_active_at for the authenticated user', function () {
    $user = User::factory()->create(['last_active_at' => null]);

    $this->actingAs($user)->postJson('/app/me/presence')->assertOk();

    expect($user->fresh()->last_active_at)->not->toBeNull();
});

test('unauthenticated heartbeat returns 401', function () {
    $this->postJson('/app/me/presence')->assertStatus(401);
});

test('heartbeat is idempotent — repeated calls just refresh the stamp', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/app/me/presence')->assertOk();
    $first = $user->fresh()->last_active_at;
    expect($first)->not->toBeNull();

    sleep(1);

    $this->actingAs($user)->postJson('/app/me/presence')->assertOk();
    $second = $user->fresh()->last_active_at;

    expect($second->greaterThan($first))->toBeTrue();
});
