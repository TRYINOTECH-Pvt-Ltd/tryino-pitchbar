<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Str;

function platformAdminUser(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

test('admin/users index filters by name/email when q is set', function () {
    $admin = platformAdminUser();
    User::factory()->create(['name' => 'Alice Anderson', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Brown', 'email' => 'bob@example.com']);

    $response = $this->actingAs($admin)->get('/admin/users?q=alice');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('filters.q', 'alice')
        ->has('users', 1)
        ->where('users.0.email', 'alice@example.com'));
});

test('admin/users empty q returns all rows', function () {
    $admin = platformAdminUser();
    User::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get('/admin/users');

    // 3 created + the admin itself = 4
    $response->assertOk()->assertInertia(fn ($p) => $p->has('users', 4));
});

test('admin/workspaces index filters by name/slug/owner', function () {
    $admin = platformAdminUser();
    $owner = User::factory()->create(['email' => 'owner@acme.com', 'name' => 'Acme Owner']);
    Workspace::factory()->create(['name' => 'Acme Inc', 'owner_user_id' => $owner->id]);
    Workspace::factory()->create(['name' => 'Other Co']);

    $response = $this->actingAs($admin)->get('/admin/workspaces?q=acme');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('filters.q', 'acme')
        ->has('workspaces', 1)
        ->where('workspaces.0.name', 'Acme Inc'));
});

test('admin/leads index filters by email/name/phone', function () {
    $admin = platformAdminUser();
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::create([
        'agent_id' => $agent->id, 'anonymous_id' => 'anon_a',
        'ip_hash' => 'x', 'ua' => 'x',
        'first_seen_at' => now(), 'last_seen_at' => now(), 'visit_count' => 1,
    ]);
    $convA = Conversation::create([
        'agent_id' => $agent->id, 'visitor_id' => $visitor->id, 'started_at' => now(),
    ]);
    $convB = Conversation::create([
        'agent_id' => $agent->id, 'visitor_id' => $visitor->id, 'started_at' => now(),
    ]);

    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $convA->id,
        'agent_id' => $agent->id,
        'email' => 'matched@example.com',
        'name' => 'Matched',
        'status' => 'new',
    ]);
    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $convB->id,
        'agent_id' => $agent->id,
        'email' => 'other@example.com',
        'name' => 'Other',
        'status' => 'new',
    ]);

    $response = $this->actingAs($admin)->get('/admin/leads?q=matched');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('filters.q', 'matched')
        ->has('leads', 1)
        ->where('leads.0.email', 'matched@example.com'));
});

test('admin/conversations index filters by page_url', function () {
    $admin = platformAdminUser();
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::create([
        'agent_id' => $agent->id,
        'anonymous_id' => 'anon_test',
        'ip_hash' => 'x',
        'ua' => 'x',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'visit_count' => 1,
    ]);

    Conversation::create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://example.com/products/red-widget',
        'started_at' => now(),
    ]);
    Conversation::create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://example.com/about',
        'started_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get('/admin/conversations?q=red-widget');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('filters.q', 'red-widget')
        ->has('conversations', 1)
        ->where('conversations.0.page_url', 'https://example.com/products/red-widget'));
});

test('admin/agents index filters by name and surfaces pagination meta', function () {
    $admin = platformAdminUser();
    $workspace = Workspace::factory()->create();
    Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Pricing Bot']);
    Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Support Bot']);

    $response = $this->actingAs($admin)->get('/admin/agents?q=Pricing');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('filters.q', 'Pricing')
        ->has('agents', 1)
        ->where('agents.0.name', 'Pricing Bot')
        ->where('pagination.current_page', 1)
        ->where('pagination.total', 1));
});

test('admin/users index returns pagination meta with default per_page', function () {
    $admin = platformAdminUser();
    User::factory()->count(5)->create();

    $response = $this->actingAs($admin)->get('/admin/users');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->has('pagination')
        ->where('pagination.per_page', 25)
        ->where('pagination.current_page', 1));
});

test('admin/users page=N respects the page parameter', function () {
    $admin = platformAdminUser();
    User::factory()->count(30)->create();

    $response = $this->actingAs($admin)->get('/admin/users?page=2');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('pagination.current_page', 2));
});

test('app/inbox filters leads by email', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::create([
        'agent_id' => $agent->id, 'anonymous_id' => 'anon_b',
        'ip_hash' => 'x', 'ua' => 'x',
        'first_seen_at' => now(), 'last_seen_at' => now(), 'visit_count' => 1,
    ]);
    $convA = Conversation::create([
        'agent_id' => $agent->id, 'visitor_id' => $visitor->id, 'started_at' => now(),
    ]);
    $convB = Conversation::create([
        'agent_id' => $agent->id, 'visitor_id' => $visitor->id, 'started_at' => now(),
    ]);

    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $convA->id,
        'agent_id' => $agent->id,
        'email' => 'sales@target.com',
        'name' => 'Sales Lead',
        'status' => 'new',
    ]);
    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $convB->id,
        'agent_id' => $agent->id,
        'email' => 'other@nope.com',
        'name' => 'Other',
        'status' => 'new',
    ]);

    $response = $this->actingAs($user)->get('/app/inbox?q=target');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('filters.q', 'target')
        ->has('leads', 1)
        ->where('leads.0.email', 'sales@target.com'));
});

test('app/inbox supports status, phone, and sort query params', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::create([
        'agent_id' => $agent->id,
        'anonymous_id' => 'anon_inbox_filters',
        'ip_hash' => 'x',
        'ua' => 'x',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'visit_count' => 1,
    ]);

    $alphaConversation = Conversation::create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subDay(),
    ]);
    $zuluConversation = Conversation::create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subHours(12),
    ]);
    $missingPhoneConversation = Conversation::create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subHours(6),
    ]);
    $newConversation = Conversation::create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'started_at' => now()->subHours(3),
    ]);

    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $alphaConversation->id,
        'agent_id' => $agent->id,
        'email' => 'alpha@example.com',
        'name' => 'Alpha Lead',
        'phone' => '111-111',
        'status' => 'qualified',
    ]);
    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $zuluConversation->id,
        'agent_id' => $agent->id,
        'email' => 'zulu@example.com',
        'name' => 'Zulu Lead',
        'phone' => '222-222',
        'status' => 'qualified',
    ]);
    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $missingPhoneConversation->id,
        'agent_id' => $agent->id,
        'email' => 'missing@example.com',
        'name' => 'Missing Phone',
        'phone' => null,
        'status' => 'qualified',
    ]);
    Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $newConversation->id,
        'agent_id' => $agent->id,
        'email' => 'new@example.com',
        'name' => 'Newest Lead',
        'phone' => '333-333',
        'status' => 'new',
    ]);

    $response = $this->actingAs($user)->get('/app/inbox?view=qualified&phone=with_phone&sort=name_asc');

    $response->assertOk()->assertInertia(fn ($p) => $p
        ->where('filters.view', 'qualified')
        ->where('filters.phone', 'with_phone')
        ->where('filters.sort', 'name_asc')
        ->has('leads', 2)
        ->where('leads.0.name', 'Alpha Lead')
        ->where('leads.1.name', 'Zulu Lead'));
});
