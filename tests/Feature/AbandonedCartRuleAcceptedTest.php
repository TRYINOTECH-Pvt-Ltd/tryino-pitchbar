<?php

use App\Models\Agent;
use App\Models\BehaviorRule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/**
 * Verifies the v2.0.0 CRITICAL #6 fix: the admin UI exposes
 * `abandoned_cart` as a valid `kind` for a behavior rule, and the
 * controller validation accepts it.
 *
 * Before this fix, the widget evaluator already polled localStorage
 * for the abandoned_cart trigger, but admins had no way to create a
 * rule of that kind — the validator hard-rejected it.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_user_id' => $this->user->id]);
    WorkspaceUser::create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $this->user->forceFill(['default_workspace_id' => $this->workspace->id])->save();
    $this->agent = Agent::factory()->create(['workspace_id' => $this->workspace->id]);
});

test('admin can create a behavior rule with kind=abandoned_cart', function () {
    actingAs($this->user);

    post("/app/agents/{$this->agent->id}/behavior", [
        'name' => 'Cart nudge',
        'kind' => 'abandoned_cart',
        'action' => ['kind' => 'open_with_message', 'message' => 'Need help with checkout?'],
        'conditions' => [],
    ])->assertRedirect();

    $rule = BehaviorRule::query()->where('agent_id', $this->agent->id)->first();

    expect($rule)->not()->toBeNull();
    expect($rule->kind)->toBe('abandoned_cart');
});

test('admin behavior page lists abandoned_cart in the KINDS dropdown source', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/js/pages/app/agents/behavior.tsx');

    expect($source)->toContain("'abandoned_cart'");
});
