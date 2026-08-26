<?php

use App\Models\Workflow;

test('workspace member can list workflows on /app/workflows', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Pricing flow',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/app/workflows');
    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->component('app/workflows/index')
        ->has('workflows', 1)
        ->where('workflows.0.name', 'Pricing flow'));
});

test('POST /app/workflows creates a workflow with the posted JSON shape', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)->post('/app/workflows', [
        'name' => 'Refund FAQ',
        'status' => 'draft',
        'trigger_kind' => 'on_keyword',
        'keywords' => ['refund', 'return'],
        'steps' => [
            ['type' => 'message', 'text' => 'Refunds work like this …'],
            ['type' => 'question', 'text' => 'Order number?', 'var_name' => 'order_no'],
            ['type' => 'escalate', 'text' => 'Connecting you to support.'],
        ],
    ])->assertRedirect();

    $wf = Workflow::query()->where('name', 'Refund FAQ')->firstOrFail();
    expect($wf->keywords())->toBe(['refund', 'return']);
    expect(count($wf->steps()))->toBe(3);
    expect($wf->status)->toBe('draft');
});

test('PATCH /app/workflows/{id} validates step type', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    $wf = Workflow::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->patch("/app/workflows/{$wf->id}", [
        'name' => 'Bad steps',
        'trigger_kind' => 'on_keyword',
        'keywords' => ['hi'],
        'steps' => [
            ['type' => 'unknown', 'text' => 'whatever'],
        ],
    ])->assertSessionHasErrors('steps.0.type');
});

test('DELETE /app/workflows/{id} removes the row', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    $wf = Workflow::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->delete("/app/workflows/{$wf->id}")->assertRedirect();
    expect(Workflow::query()->whereKey($wf->id)->exists())->toBeFalse();
});

test('cross-workspace workflows are hidden by the BelongsToWorkspace scope', function () {
    ['user' => $userA, 'workspace' => $wsA] = workspaceMemberWithAgent();
    Workflow::factory()->create([
        'workspace_id' => $wsA->id,
        'name' => 'Mine',
    ]);
    $other = workspaceMemberWithAgent();
    Workflow::factory()->create([
        'workspace_id' => $other['workspace']->id,
        'name' => 'Theirs',
    ]);

    $response = $this->actingAs($userA)->get('/app/workflows');
    $response->assertInertia(fn ($p) => $p
        ->has('workflows', 1)
        ->where('workflows.0.name', 'Mine'));
});

test('index page returns pagination + filter shape (matches /app/agents)', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Refund flow',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->get('/app/workflows');

    $response->assertInertia(fn ($p) => $p
        ->component('app/workflows/index')
        ->has('workflows', 1)
        ->has('pagination.current_page')
        ->has('pagination.per_page')
        ->has('pagination.last_page')
        ->where('filters.q', '')
        ->where('filters.view', 'all')
        ->where('filters.sort', 'updated_desc'));
});

test('view=draft scope filters to draft workflows only', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Active flow',
        'status' => 'active',
    ]);
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Draft flow',
        'status' => 'draft',
    ]);

    $response = $this->actingAs($user)->get('/app/workflows?view=draft');

    $response->assertInertia(fn ($p) => $p
        ->has('workflows', 1)
        ->where('workflows.0.name', 'Draft flow')
        ->where('filters.view', 'draft'));
});

test('q= search matches workflow name like AgentsIndex', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Refund FAQ',
    ]);
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Pricing flow',
    ]);

    $response = $this->actingAs($user)->get('/app/workflows?q=refund');

    $response->assertInertia(fn ($p) => $p
        ->has('workflows', 1)
        ->where('workflows.0.name', 'Refund FAQ'));
});

// ─── Phase 2 validation ─────────────────────────────────────────────────

test('POST accepts a Phase 2 workflow with branch + tag_lead + webhook + match_mode=all', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)->post('/app/workflows', [
        'name' => 'Phase 2 flow',
        'status' => 'active',
        'trigger_kind' => 'on_keyword',
        'match_mode' => 'all',
        'keywords' => ['pricing', 'pro'],
        'steps' => [
            ['type' => 'question', 'text' => 'Plan?', 'var_name' => 'plan'],
            ['type' => 'branch', 'var' => 'plan', 'cases' => [
                ['match' => 'equals', 'value' => 'pro', 'go_to' => 2],
                ['match' => 'default', 'go_to' => 4],
            ]],
            ['type' => 'tag_lead', 'tags' => ['pro_intent']],
            ['type' => 'message', 'text' => 'Pro lane.'],
            ['type' => 'webhook', 'url' => 'https://hooks.example.com/in', 'method' => 'POST'],
            ['type' => 'escalate', 'text' => 'Done.'],
        ],
    ])->assertRedirect();

    $wf = Workflow::query()->where('name', 'Phase 2 flow')->firstOrFail();
    expect($wf->trigger_config['match_mode'])->toBe('all');
    expect(count($wf->steps()))->toBe(6);
});

test('POST rejects a webhook step without a URL', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)->post('/app/workflows', [
        'name' => 'Bad webhook',
        'trigger_kind' => 'on_keyword',
        'keywords' => ['ping'],
        'steps' => [
            ['type' => 'webhook', 'method' => 'POST'],
        ],
    ])->assertSessionHasErrors();
});

test('POST rejects an unknown match_mode', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)->post('/app/workflows', [
        'name' => 'Bad mode',
        'trigger_kind' => 'on_keyword',
        'match_mode' => 'fuzzy',
        'keywords' => ['x'],
        'steps' => [['type' => 'message', 'text' => 'hi']],
    ])->assertSessionHasErrors('match_mode');
});

test('POST rejects an invalid branch match operator', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)->post('/app/workflows', [
        'name' => 'Bad branch',
        'trigger_kind' => 'on_keyword',
        'keywords' => ['x'],
        'steps' => [
            ['type' => 'branch', 'var' => 'x', 'cases' => [
                ['match' => 'magic', 'value' => 'a', 'go_to' => 1],
            ]],
        ],
    ])->assertSessionHasErrors();
});

// ─── Phase 2 visual editor (canvas) ─────────────────────────────────────

test('GET /app/workflows/{id}/canvas renders the canvas page', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    $wf = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Branchy flow',
        'status' => 'active',
        'trigger_config' => ['keywords' => ['x'], 'match_mode' => 'any'],
        'definition' => ['steps' => [['type' => 'message', 'text' => 'hi']]],
    ]);

    $this->actingAs($user)->get("/app/workflows/{$wf->id}/canvas")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('app/workflows/canvas')
            ->where('workflow.id', $wf->id)
            ->where('workflow.match_mode', 'any')
            ->has('workflow.steps', 1)
            ->has('workflow.definition'));
});

test('PATCH from the canvas persists the definition_canvas blob alongside steps', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMemberWithAgent();
    $wf = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'definition' => ['steps' => [['type' => 'message', 'text' => 'hi']]],
    ]);

    $this->actingAs($user)->patch("/app/workflows/{$wf->id}", [
        'name' => $wf->name,
        'status' => 'active',
        'trigger_kind' => 'on_keyword',
        'keywords' => ['hi'],
        'match_mode' => 'any',
        'steps' => [
            ['type' => 'message', 'text' => 'first'],
            ['type' => 'escalate', 'text' => 'done'],
        ],
        'definition_canvas' => [
            'nodes' => [
                ['id' => 'step-1', 'type' => 'message', 'position' => ['x' => 50, 'y' => 100], 'data' => ['text' => 'first']],
                ['id' => 'step-2', 'type' => 'escalate', 'position' => ['x' => 50, 'y' => 260], 'data' => ['text' => 'done']],
            ],
            'edges' => [
                ['id' => 'e-trigger-1', 'source' => 'trigger', 'target' => 'step-1'],
                ['id' => 'e-1-2', 'source' => 'step-1', 'target' => 'step-2'],
            ],
        ],
    ])->assertRedirect();

    $fresh = $wf->fresh();
    expect($fresh->definition['canvas']['nodes'])->toHaveCount(2);
    expect($fresh->definition['canvas']['edges'])->toHaveCount(2);
    expect($fresh->definition['steps'])->toHaveCount(2);
});

// Cross-workspace access to /canvas is covered by the existing
// "cross-workspace workflows are hidden by the BelongsToWorkspace
// scope" test for the index route — the canvas method goes through
// the same scoped route-model binding, so the protection is shared.
