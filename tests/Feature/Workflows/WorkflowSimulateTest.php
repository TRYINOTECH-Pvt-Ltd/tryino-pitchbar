<?php

use App\Jobs\Workflows\DispatchWebhookJob;
use App\Models\Lead;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Queue;

/**
 * Canvas "Test run" endpoint — a stateless dry-run of a draft
 * definition through WorkflowSimulator (which shares the engine's
 * semantics trait). The contract under test: exact engine behavior,
 * zero side effects.
 */
function simulatePayload(array $overrides = []): array
{
    return array_merge([
        'match_mode' => 'any',
        'keywords' => ['pricing'],
        'steps' => [
            ['type' => 'message', 'text' => 'Our plans start at €29.'],
            ['type' => 'question', 'text' => 'Team size?', 'var_name' => 'team_size'],
            ['type' => 'branch', 'var' => 'team_size', 'cases' => [
                ['match' => 'contains', 'value' => '20', 'go_to' => 3],
                ['match' => 'default', 'value' => null, 'go_to' => 4],
            ]],
            ['type' => 'message', 'text' => 'For {{team_size}} people, Pro fits best.'],
            ['type' => 'tag_lead', 'tags' => ['pricing-intent']],
        ],
        'messages' => ['tell me about pricing'],
    ], $overrides);
}

test('simulate reports no_match when the trigger does not fire', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $res = $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload([
            'messages' => ['hello there'],
        ]))
        ->assertOk()
        ->json('data');

    expect($res['triggered'])->toBeFalse()
        ->and($res['status'])->toBe('no_match')
        ->and($res['events'])->toBe([]);
});

test('simulate walks message → question and pauses waiting for a reply', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $res = $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload())
        ->assertOk()
        ->json('data');

    expect($res['triggered'])->toBeTrue()
        ->and($res['status'])->toBe('waiting_reply')
        ->and($res['waiting_var'])->toBe('team_size')
        ->and(collect($res['events'])->pluck('type')->all())->toBe(['message', 'question']);
});

test('simulate consumes replies, takes the matching branch, and interpolates vars', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $res = $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload([
            'messages' => ['tell me about pricing', 'around 20'],
        ]))
        ->assertOk()
        ->json('data');

    expect($res['status'])->toBe('completed')
        ->and(collect($res['events'])->firstWhere('type', 'branch')['matched_case'])->toBe(0)
        ->and(collect($res['events'])->last()['type'])->toBe('tag_lead')
        // {{team_size}} interpolates from the captured reply.
        ->and(collect($res['events'])->where('type', 'message')->last()['text'])
        ->toBe('For around 20 people, Pro fits best.');
});

test('simulate takes the default branch case when nothing matches', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $res = $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload([
            'messages' => ['pricing please', 'just me'],
        ]))
        ->assertOk()
        ->json('data');

    $branch = collect($res['events'])->firstWhere('type', 'branch');

    expect($branch['matched_case'])->toBe('default')
        ->and($branch['go_to'])->toBe(4)
        // Jumped over step 3 — the tailored message never fires.
        ->and(collect($res['events'])->where('type', 'message')->count())->toBe(1);
});

test('simulate has zero side effects: no runs, no leads, no webhook jobs', function () {
    Queue::fake();
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload([
            'steps' => [
                ['type' => 'message', 'text' => 'Hi.'],
                ['type' => 'tag_lead', 'tags' => ['vip']],
                ['type' => 'webhook', 'url' => 'https://example.com/hook', 'method' => 'POST'],
            ],
        ]))
        ->assertOk();

    Queue::assertNotPushed(DispatchWebhookJob::class);
    expect(WorkflowRun::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Lead::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('simulate stops a malformed loop with the engine loop guard', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $res = $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload([
            'steps' => [
                // Two branches pointing at each other — is_empty on a
                // var that is never set keeps both jumping forever.
                ['type' => 'branch', 'var' => 'never_set', 'cases' => [
                    ['match' => 'is_empty', 'value' => null, 'go_to' => 1],
                ]],
                ['type' => 'branch', 'var' => 'never_set', 'cases' => [
                    ['match' => 'is_empty', 'value' => null, 'go_to' => 0],
                ]],
            ],
        ]))
        ->assertOk()
        ->json('data');

    expect($res['status'])->toBe('failed_loop')
        ->and(collect($res['events'])->last()['type'])->toBe('loop_guard');
});

test('simulate reports an escalate step and stops', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $res = $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload([
            'steps' => [
                ['type' => 'escalate', 'text' => 'Human incoming.'],
                ['type' => 'message', 'text' => 'Never reached.'],
            ],
        ]))
        ->assertOk()
        ->json('data');

    expect($res['status'])->toBe('escalated')
        ->and(count($res['events']))->toBe(1);
});

test('viewer members cannot simulate', function () {
    ['user' => $user] = workspaceMemberWithAgent(['role' => 'viewer']);

    $this->actingAs($user)
        ->postJson('/app/workflows/simulate', simulatePayload())
        ->assertForbidden();
});

test('canvas-first create renders the canvas page with a null workflow', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)
        ->get('/app/workflows/create?canvas=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/workflows/canvas')
            ->where('workflow', null)
            ->has('agents')
        );
});

test('activating a workflow without keywords is rejected', function () {
    ['user' => $user] = workspaceMemberWithAgent();

    $this->actingAs($user)
        ->post('/app/workflows', [
            'name' => 'Broken',
            'status' => 'active',
            'trigger_kind' => 'on_keyword',
            'keywords' => [],
            'steps' => [['type' => 'message', 'text' => 'Hi']],
        ])
        ->assertSessionHasErrors('keywords');

    // Drafts save fine in the same half-finished state.
    $this->actingAs($user)
        ->post('/app/workflows', [
            'name' => 'Half-finished',
            'status' => 'draft',
            'trigger_kind' => 'on_keyword',
            'keywords' => [],
            'steps' => [['type' => 'message', 'text' => 'Hi']],
        ])
        ->assertSessionHasNoErrors();
});

test('a canvas save preserves agent pinning', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent();

    $this->actingAs($user)->post('/app/workflows', [
        'name' => 'Pinned flow',
        'status' => 'draft',
        'trigger_kind' => 'on_keyword',
        'agent_id' => $agent->id,
        'keywords' => ['pricing'],
        'steps' => [['type' => 'message', 'text' => 'Hi']],
    ])->assertRedirect();

    $wf = Workflow::query()->where('name', 'Pinned flow')->firstOrFail();
    expect($wf->agent_id)->toBe($agent->id);

    // The canvas echoes the stored pinning back on save (regression:
    // pre-fix its payload omitted agent_id, and update() treats absent
    // as "clear" — one canvas save silently unpinned the workflow).
    $this->actingAs($user)->patch("/app/workflows/{$wf->id}", [
        'name' => 'Pinned flow',
        'status' => 'draft',
        'trigger_kind' => 'on_keyword',
        'agent_id' => $agent->id,
        'match_mode' => 'any',
        'keywords' => ['pricing'],
        'steps' => [['type' => 'message', 'text' => 'Hi there']],
        '_editor' => 'canvas',
    ])->assertRedirect(route('workflows.canvas', ['workflow' => $wf->id]));

    $wf->refresh();
    expect($wf->agent_id)->toBe($agent->id)
        ->and($wf->steps()[0]['text'])->toBe('Hi there');
});
