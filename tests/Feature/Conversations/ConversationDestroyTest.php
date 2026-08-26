<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function destroyFixture(string $agentId, array $messages = [], array $convOverrides = []): Conversation
{
    $visitor = Visitor::factory()->create(['agent_id' => $agentId]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agentId,
        'visitor_id' => $visitor->id,
        ...$convOverrides,
    ]);

    foreach ($messages as $m) {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conv->id,
            'role' => $m['role'],
            'content' => $m['content'],
            'citations' => json_encode([]),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'model' => null,
            'confidence' => null,
            'created_at' => now(),
        ]);
    }

    return $conv;
}

test('admin can delete a single conversation and child rows cascade', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $conv = destroyFixture($agent->id, [
        ['role' => 'user', 'content' => 'hello'],
        ['role' => 'assistant', 'content' => 'hi there'],
    ]);
    Lead::factory()->create([
        'agent_id' => $agent->id,
        'conversation_id' => $conv->id,
        'email' => 'lead@example.com',
    ]);

    $this->actingAs($user)
        ->delete("/app/conversations/{$conv->id}")
        ->assertRedirect();

    expect(Conversation::query()->withoutGlobalScopes()->find($conv->id))->toBeNull();
    expect(Message::query()->where('conversation_id', $conv->id)->count())->toBe(0);
    expect(Lead::query()->withoutGlobalScopes()->where('conversation_id', $conv->id)->count())->toBe(0);
});

test('viewer cannot delete a conversation', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'viewer']);
    $conv = destroyFixture($agent->id);

    $this->actingAs($user)
        ->delete("/app/conversations/{$conv->id}")
        ->assertForbidden();

    expect(Conversation::query()->withoutGlobalScopes()->find($conv->id))->not->toBeNull();
});

test('editor cannot delete — destruction is members-and-up only', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'editor']);
    $conv = destroyFixture($agent->id);

    $this->actingAs($user)
        ->delete("/app/conversations/{$conv->id}")
        ->assertForbidden();
});

test('cross-tenant delete attempt is rejected by ConversationPolicy', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);
    $foreignConv = destroyFixture($foreign['agent']->id);

    // Route-model binding finds the row (scope wouldn't help here — the
    // request user IS a workspace admin of their own ws). Policy is the
    // guard: ConversationPolicy::delete walks the conversation → agent
    // → workspace chain, and the user has no role in the foreign one.
    $this->actingAs($user)
        ->delete("/app/conversations/{$foreignConv->id}")
        ->assertForbidden();

    expect(Conversation::query()->withoutGlobalScopes()->find($foreignConv->id))->not->toBeNull();
});

test('bulkDestroy mode=empty wipes 0-message conversations only', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $empty1 = destroyFixture($agent->id); // no messages
    $empty2 = destroyFixture($agent->id); // no messages
    $engaged = destroyFixture($agent->id, [
        ['role' => 'user', 'content' => 'real question'],
    ]);

    $this->actingAs($user)
        ->post('/app/conversations/bulk-destroy', ['mode' => 'empty'])
        ->assertRedirect();

    expect(Conversation::query()->withoutGlobalScopes()->find($empty1->id))->toBeNull();
    expect(Conversation::query()->withoutGlobalScopes()->find($empty2->id))->toBeNull();
    expect(Conversation::query()->withoutGlobalScopes()->find($engaged->id))->not->toBeNull();
});

test('bulkDestroy mode=ids only deletes the listed conversations', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    $keep = destroyFixture($agent->id, [['role' => 'user', 'content' => 'keep me']]);
    $drop = destroyFixture($agent->id, [['role' => 'user', 'content' => 'drop me']]);

    $this->actingAs($user)
        ->post('/app/conversations/bulk-destroy', [
            'mode' => 'ids',
            'ids' => [$drop->id],
        ])
        ->assertRedirect();

    expect(Conversation::query()->withoutGlobalScopes()->find($drop->id))->toBeNull();
    expect(Conversation::query()->withoutGlobalScopes()->find($keep->id))->not->toBeNull();
});

test('bulkDestroy mode=ids cannot reach across tenant via the workspace scope', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'admin']);
    $foreign = workspaceMemberWithAgent(['role' => 'admin']);
    $foreignConv = destroyFixture($foreign['agent']->id, [
        ['role' => 'user', 'content' => 'cross-tenant'],
    ]);

    $this->actingAs($user)
        ->post('/app/conversations/bulk-destroy', [
            'mode' => 'ids',
            'ids' => [$foreignConv->id],
        ])
        ->assertRedirect();

    // Workspace scope on Conversation::delete blocked the cross-tenant id.
    expect(Conversation::query()->withoutGlobalScopes()->find($foreignConv->id))->not->toBeNull();
});

test('viewer cannot bulk-destroy', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'viewer']);
    destroyFixture($agent->id); // empty

    $this->actingAs($user)
        ->post('/app/conversations/bulk-destroy', ['mode' => 'empty'])
        ->assertForbidden();
});

test('engaged-only filter hides 0-message rows by default; show=all reveals them', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    destroyFixture($agent->id); // empty
    destroyFixture($agent->id, [
        ['role' => 'user', 'content' => 'I am engaged'],
    ]);

    $this->actingAs($user)
        ->get('/app/conversations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.show', 'engaged')
            ->where('totals.empty', 1)
            ->has('conversations', 1),
        );

    $this->actingAs($user)
        ->get('/app/conversations?show=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.show', 'all')
            ->has('conversations', 2),
        );
});

test('per-agent index also respects engaged-only by default', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'admin']);
    destroyFixture($agent->id); // empty
    destroyFixture($agent->id, [['role' => 'user', 'content' => 'engaged']]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/conversations")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.show', 'engaged')
            ->where('totals.empty', 1)
            ->has('conversations', 1),
        );
});

test('can_delete prop is true for admin, false for viewer', function () {
    ['user' => $admin, 'workspace' => $workspace] = workspaceMemberWithAgent(['role' => 'admin']);
    $this->actingAs($admin)
        ->get('/app/conversations')
        ->assertInertia(fn ($page) => $page->where('can_delete', true));

    ['user' => $viewer] = workspaceMemberWithAgent(['role' => 'viewer']);
    $this->actingAs($viewer)
        ->get('/app/conversations')
        ->assertInertia(fn ($page) => $page->where('can_delete', false));
});
