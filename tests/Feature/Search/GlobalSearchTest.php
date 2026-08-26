<?php

use App\Enums\PlatformRole;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('finds agents by name', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(
        ['role' => 'owner'],
        ['name' => 'MacBook Sales Bot'],
    );

    $this->actingAs($user)
        ->getJson('/app/search?q=macbook')
        ->assertOk()
        ->assertJsonPath('data.agents.0.id', $agent->id)
        ->assertJsonPath('data.agents.0.name', 'MacBook Sales Bot');
});

test('finds conversations whose page_url matches', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'page_url' => 'https://shop.com/products/red-pen',
    ]);

    $this->actingAs($user)
        ->getJson('/app/search?q=red-pen')
        ->assertOk()
        ->assertJsonPath('data.conversations.0.id', $conv->id);
});

test('finds conversations whose messages contain the term', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);

    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conv->id,
        'role' => 'user',
        'content' => 'How much is the MacBook Air refund window?',
        'citations' => json_encode([]),
        'tokens_in' => 0,
        'tokens_out' => 0,
        'latency_ms' => 0,
        'model' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/app/search?q=refund')
        ->assertOk()
        ->assertJsonPath('data.conversations.0.id', $conv->id);
});

test('finds leads by email or name', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);

    $lead = Lead::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conv->id,
        'agent_id' => $agent->id,
        'email' => 'alice@bigcustomer.com',
        'name' => 'Alice Johnson',
        'fields' => [],
        'status' => 'new',
    ]);

    $this->actingAs($user)
        ->getJson('/app/search?q=alice')
        ->assertOk()
        ->assertJsonPath('data.leads.0.id', $lead->id);

    $this->actingAs($user)
        ->getJson('/app/search?q=bigcustomer')
        ->assertOk()
        ->assertJsonPath('data.leads.0.id', $lead->id);
});

test('returns empty envelope when q is missing or shorter than 2 chars', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'owner']);

    $this->actingAs($user)
        ->getJson('/app/search')
        ->assertOk()
        ->assertJsonPath('data.agents', [])
        ->assertJsonPath('data.conversations', [])
        ->assertJsonPath('data.leads', []);

    $this->actingAs($user)
        ->getJson('/app/search?q=a')
        ->assertOk()
        ->assertJsonPath('data.agents', []);
});

test('multi-tenancy: never returns rows from another workspace', function () {
    ['user' => $user] = workspaceMember(['role' => 'owner']);

    $other = workspaceMemberWithAgent(['role' => 'owner'], ['name' => 'OtherShop Bot']);
    $otherVisitor = Visitor::factory()->create(['agent_id' => $other['agent']->id]);
    Conversation::factory()->create([
        'agent_id' => $other['agent']->id,
        'visitor_id' => $otherVisitor->id,
        'page_url' => 'https://othershop.com/private',
    ]);

    $this->actingAs($user)
        ->getJson('/app/search?q=othershop')
        ->assertOk()
        ->assertJsonPath('data.agents', [])
        ->assertJsonPath('data.conversations', []);
});

test('super-admin is redirected from /app/search (customer surface)', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    Workspace::factory()->create(['owner_user_id' => $admin->id])->id;

    $this->actingAs($admin)
        ->get('/app/search?q=anything')
        ->assertRedirect('/admin');
});
