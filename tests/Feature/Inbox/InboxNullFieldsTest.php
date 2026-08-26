<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Regression: inbox index used to crash with `TypeError: cannot read
 * 'slice' of null` when a lead had both `email` and `name` set to null.
 * The React row computed `label = lead.name ?? lead.email` and then
 * called `label.slice(0, 1)` for the avatar bubble — null killed the
 * whole page. Now the row falls back to a localized "Unnamed lead"
 * string.
 *
 * This test asserts the server still serves a 200 with the lead in
 * the payload (server is innocent — the bug was purely client-side
 * null-handling), but the regression is captured in repo so anyone
 * reading the failing client-side trace can find the server contract
 * here.
 */
function inboxOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return compact('user', 'workspace', 'agent');
}

test('inbox renders 200 when a lead has null email AND null name', function () {
    ['user' => $user, 'agent' => $agent] = inboxOwner();
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    Lead::factory()->create([
        'agent_id' => $agent->id,
        'conversation_id' => $conv->id,
        'email' => null,
        'name' => null,
        'phone' => null,
    ]);

    $this->actingAs($user)
        ->get('/app/inbox')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('app/inbox/index')
            ->has('leads', 1)
            ->where('leads.0.email', null)
            ->where('leads.0.name', null));
});

test('inbox renders 200 when leads carry mixed null/string field combinations', function () {
    ['user' => $user, 'agent' => $agent] = inboxOwner();

    // Three leads exercising every null permutation that has hit prod.
    foreach ([
        ['email' => null, 'name' => null, 'phone' => null],
        ['email' => 'has-email@only.test', 'name' => null, 'phone' => null],
        ['email' => null, 'name' => 'Has Name Only', 'phone' => null],
    ] as $row) {
        $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
        $conv = Conversation::factory()->create([
            'agent_id' => $agent->id,
            'visitor_id' => $visitor->id,
        ]);
        Lead::factory()->create([
            'agent_id' => $agent->id,
            'conversation_id' => $conv->id,
            'email' => $row['email'],
            'name' => $row['name'],
            'phone' => $row['phone'],
        ]);
    }

    $this->actingAs($user)
        ->get('/app/inbox')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('leads', 3));
});

test('inbox detail page renders 200 for a lead with null email + name', function () {
    ['user' => $user, 'agent' => $agent] = inboxOwner();
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $lead = Lead::factory()->create([
        'agent_id' => $agent->id,
        'conversation_id' => $conv->id,
        'email' => null,
        'name' => null,
    ]);

    $this->actingAs($user)
        ->get("/app/inbox/{$lead->id}")
        ->assertOk();
});

test('inbox index source still serves the "Unnamed lead" fallback string', function () {
    // Sanity check on the JSX file — guarantees the fallback string
    // didn't get accidentally removed by a future refactor.
    // Match the two halves independently so Prettier line-wrapping the
    // `lead.email ?? t('Unnamed lead')` expression doesn't break the guard.
    $source = file_get_contents(base_path('resources/js/pages/app/inbox/index.tsx'));
    expect($source)->toContain('lead.email ??')
        ->toContain("t('Unnamed lead')");
});
