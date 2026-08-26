<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationTag;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Inertia\Testing\AssertableInertia;

function tagsMember(?Workspace $workspace = null, string $role = 'admin'): array
{
    $user = User::factory()->create();
    $workspace = $workspace ?? Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('store creates a tag scoped to the active workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = tagsMember();

    $this->actingAs($user)
        ->post('/app/settings/tags', [
            'label' => 'Billing',
            'color' => '#ef4444',
        ])
        ->assertRedirect();

    $tag = ConversationTag::query()->withoutGlobalScopes()->first();
    expect($tag)->not->toBeNull();
    expect($tag->workspace_id)->toBe($workspace->id);
    expect($tag->label)->toBe('Billing');
    expect($tag->color)->toBe('#ef4444');
});

test('attach + detach apply and remove a tag on a conversation', function () {
    ['user' => $user, 'workspace' => $workspace] = tagsMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $tag = ConversationTag::create([
        'workspace_id' => $workspace->id,
        'label' => 'Bug',
        'color' => '#f59e0b',
    ]);

    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/tags/{$tag->id}")
        ->assertOk();

    expect($conv->fresh()->tags()->count())->toBe(1);

    $this->actingAs($user)
        ->deleteJson("/app/conversations/{$conv->id}/tags/{$tag->id}")
        ->assertOk();

    expect($conv->fresh()->tags()->count())->toBe(0);
});

test('cross-tenant tag is invisible to update / destroy', function () {
    ['user' => $user] = tagsMember();
    $foreignWorkspace = Workspace::factory()->create();
    $tag = ConversationTag::create([
        'workspace_id' => $foreignWorkspace->id,
        'label' => 'Foreign',
        'color' => '#10b981',
    ]);

    $this->actingAs($user)
        ->patch("/app/settings/tags/{$tag->id}", [
            'label' => 'pwned',
            'color' => '#000000',
        ])
        ->assertNotFound();

    expect($tag->fresh()->label)->toBe('Foreign');
});

test('workspace conversations index filters by tag', function () {
    ['user' => $user, 'workspace' => $workspace] = tagsMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    $billingTag = ConversationTag::create([
        'workspace_id' => $workspace->id,
        'label' => 'Billing',
        'color' => '#ef4444',
    ]);

    $tagged = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $untagged = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    $tagged->tags()->attach($billingTag->id, [
        'applied_by' => $user->id,
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("/app/conversations?tag={$billingTag->id}&show=all")
        ->assertInertia(function (AssertableInertia $page) use ($tagged, $untagged) {
            $ids = collect($page->toArray()['props']['conversations'])
                ->pluck('id')
                ->all();
            expect($ids)->toContain($tagged->id);
            expect($ids)->not->toContain($untagged->id);
        });
});

test('show payload exposes applied + available tags', function () {
    ['user' => $user, 'workspace' => $workspace] = tagsMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    $applied = ConversationTag::create([
        'workspace_id' => $workspace->id,
        'label' => 'Refund',
        'color' => '#a855f7',
    ]);
    ConversationTag::create([
        'workspace_id' => $workspace->id,
        'label' => 'Bug',
        'color' => '#f59e0b',
    ]);

    $conv->tags()->attach($applied->id, [
        'applied_by' => $user->id,
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get("/app/conversations/{$conv->id}")
        ->assertInertia(function (AssertableInertia $page) {
            $page->has('applied_tags', 1)
                ->has('available_tags', 2);
        });
});
