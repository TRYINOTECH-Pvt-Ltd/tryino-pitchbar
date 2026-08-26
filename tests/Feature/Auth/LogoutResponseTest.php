<?php

use App\Models\User;

test('Inertia XHR logout returns 409 with X-Inertia-Location header (no overlay glitch)', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/logout');

    expect($response->getStatusCode())->toBe(409);
    expect($response->headers->get('X-Inertia-Location'))->not->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('plain browser logout (non-XHR) still returns a 302 redirect', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    expect(auth()->check())->toBeFalse();
});

test('JSON-API logout (no Inertia header, accepts JSON) still returns 204', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/logout');

    expect($response->getStatusCode())->toBe(204);
    expect(auth()->check())->toBeFalse();
});
