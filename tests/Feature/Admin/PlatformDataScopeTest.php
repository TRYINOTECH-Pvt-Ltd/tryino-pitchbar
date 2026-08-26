<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;

test('admin sees workspaces across all tenants on /admin/workspaces', function () {
    Workspace::factory()->count(3)->create();

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $response = $this->actingAs($admin)->get('/admin/workspaces');
    $response->assertOk();
    $response->assertInertia(fn ($p) => $p->component('admin/workspaces/index')->has('workspaces', 3));
});

test('admin sees agents across all tenants on /admin/agents', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    Agent::factory()->create(['workspace_id' => $a->id]);
    Agent::factory()->create(['workspace_id' => $b->id]);

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $response = $this->actingAs($admin)->get('/admin/agents');
    $response->assertOk();
    $response->assertInertia(fn ($p) => $p->component('admin/agents/index')->has('agents', 2));
});

test('admin sees leads across all tenants on /admin/leads', function () {
    Lead::factory()->count(3)->create();

    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $this->actingAs($admin)->get('/admin/leads')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('leads', 3));
});

test('promoting and demoting a user via /admin/users updates the role', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $target = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($admin)->patch("/admin/users/{$target->id}/role", ['role' => 'super_admin'])->assertRedirect();
    expect($target->fresh()->isSuperAdmin())->toBeTrue();

    $this->actingAs($admin)->patch("/admin/users/{$target->id}/role", ['role' => 'customer'])->assertRedirect();
    expect($target->fresh()->isSuperAdmin())->toBeFalse();
});

test('the last super_admin cannot demote themselves', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)->patch("/admin/users/{$admin->id}/role", ['role' => 'customer'])->assertRedirect();
    expect($admin->fresh()->isSuperAdmin())->toBeTrue();
});

test('a customer hitting /admin/users/{me}/role cannot use it (404 from middleware)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)
        ->patch("/admin/users/{$customer->id}/role", ['role' => 'super_admin'])
        ->assertNotFound();
    expect($customer->fresh()->isSuperAdmin())->toBeFalse();
});
