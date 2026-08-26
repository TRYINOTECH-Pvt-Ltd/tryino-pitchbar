<?php

use App\Jobs\Workflows\DispatchWebhookJob;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Support\Facades\Bus;

function flowFixture(array $opts = []): array
{
    $bag = workspaceMemberWithAgent();
    $agent = $bag['agent'];
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    return ['workspace' => $bag['workspace'], 'agent' => $agent, 'conversation' => $conversation];
}

test('handleTurn returns false when no workflow matches', function () {
    ['conversation' => $conv] = flowFixture();

    $emitted = [];
    $handled = (new WorkflowEngine)->handleTurn(
        $conv,
        'something completely unrelated',
        function (string $text) use (&$emitted) {
            $emitted[] = $text;
        },
    );

    expect($handled)->toBeFalse();
    expect($emitted)->toBe([]);
    expect(WorkflowRun::query()->count())->toBe(0);
});

test('matching keyword starts a run + emits the first message + pauses on question', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    $wf = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_kind' => 'on_keyword',
        'trigger_config' => ['keywords' => ['pricing']],
        'definition' => [
            'steps' => [
                ['type' => 'message', 'text' => 'We have three plans.'],
                ['type' => 'question', 'text' => 'Which plan interests you?', 'var_name' => 'plan_interest'],
                ['type' => 'message', 'text' => "Great — here's the breakdown."],
            ],
        ],
    ]);
    expect($wf->keywords())->toBe(['pricing']);
    expect($wf->status)->toBe('active');

    $engine = new WorkflowEngine;
    $matched = $engine->findMatching($conv, 'tell me about pricing please');
    expect($matched)->not->toBeNull();
    expect($matched->id)->toBe($wf->id);

    $emitted = [];
    $handled = $engine->handleTurn(
        $conv,
        'tell me about pricing please',
        function (string $text) use (&$emitted) {
            $emitted[] = $text;
        },
    );

    expect($handled)->toBeTrue();
    expect($emitted)->toBe(['We have three plans.', 'Which plan interests you?']);

    $run = WorkflowRun::query()->where('conversation_id', $conv->id)->firstOrFail();
    expect($run->status)->toBe('running');
    expect($run->current_step_index)->toBe(1);
});

test('next visitor turn resumes the paused run + records the answer', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_kind' => 'on_keyword',
        'trigger_config' => ['keywords' => ['pricing']],
        'definition' => [
            'steps' => [
                ['type' => 'message', 'text' => 'We have three plans.'],
                ['type' => 'question', 'text' => 'Which plan interests you?', 'var_name' => 'plan_interest'],
                ['type' => 'message', 'text' => 'Great — you picked {{plan_interest}}.'],
            ],
        ],
    ]);

    $engine = new WorkflowEngine;
    $engine->handleTurn($conv, 'pricing', function (string $text) {});

    $emitted = [];
    $handled = $engine->handleTurn(
        $conv,
        'Pro plan looks good',
        function (string $text) use (&$emitted) {
            $emitted[] = $text;
        },
    );

    expect($handled)->toBeTrue();
    expect($emitted)->toBe(['Great — you picked Pro plan looks good.']);

    $run = WorkflowRun::query()->where('conversation_id', $conv->id)->firstOrFail();
    expect($run->status)->toBe('completed');
    expect($run->vars['plan_interest'])->toBe('Pro plan looks good');
});

test('escalate step flags the conversation for human takeover', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_kind' => 'on_keyword',
        'trigger_config' => ['keywords' => ['speak to a human']],
        'definition' => [
            'steps' => [
                ['type' => 'escalate', 'text' => 'Connecting you now…'],
            ],
        ],
    ]);

    $emitted = [];
    $handled = (new WorkflowEngine)->handleTurn(
        $conv,
        'I need to speak to a human',
        function (string $text) use (&$emitted) {
            $emitted[] = $text;
        },
    );

    expect($handled)->toBeTrue();
    expect($emitted)->toBe(['Connecting you now…']);
    $run = WorkflowRun::query()->where('conversation_id', $conv->id)->firstOrFail();
    expect($run->status)->toBe('completed');
    expect($run->vars['escalated'] ?? false)->toBeTrue();
});

test('draft + disabled workflows never match', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'draft',
        'trigger_config' => ['keywords' => ['pricing']],
        'definition' => ['steps' => [['type' => 'message', 'text' => 'hi']]],
    ]);
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'disabled',
        'trigger_config' => ['keywords' => ['pricing']],
        'definition' => ['steps' => [['type' => 'message', 'text' => 'hi']]],
    ]);

    $handled = (new WorkflowEngine)->handleTurn(
        $conv,
        'pricing',
        function (string $text) {},
    );

    expect($handled)->toBeFalse();
});

test('cross-workspace workflows do not match the conversations agent', function () {
    ['conversation' => $conv] = flowFixture();
    // A workflow in a DIFFERENT workspace with matching keywords.
    $other = workspaceMemberWithAgent();
    Workflow::factory()->create([
        'workspace_id' => $other['workspace']->id,
        'agent_id' => $other['agent']->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['pricing']],
        'definition' => ['steps' => [['type' => 'message', 'text' => 'leak']]],
    ]);

    $emitted = [];
    $handled = (new WorkflowEngine)->handleTurn(
        $conv,
        'pricing question',
        function (string $text) use (&$emitted) {
            $emitted[] = $text;
        },
    );

    expect($handled)->toBeFalse();
    expect($emitted)->toBe([]);
});

test('handleTurn refuses to resume a WorkflowRun whose workspace mismatches the conversation', function () {
    // Defence-in-depth (audit 2026-05-16): a stale WorkflowRun row
    // pointing at a different workspace must not resume on this
    // conversation, even if the run's conversation_id somehow lines up.
    ['conversation' => $conv, 'agent' => $agent] = flowFixture();
    $other = workspaceMemberWithAgent();
    $otherFlow = Workflow::factory()->create([
        'workspace_id' => $other['workspace']->id,
        'agent_id' => $other['agent']->id,
        'status' => 'active',
        'definition' => ['steps' => [['type' => 'message', 'text' => 'should-never-emit']]],
    ]);
    WorkflowRun::query()->withoutGlobalScopes()->create([
        'workspace_id' => $other['workspace']->id,
        'workflow_id' => $otherFlow->id,
        'conversation_id' => $conv->id,
        'status' => 'running',
        'current_step_index' => 0,
        'vars' => [],
        'started_at' => now(),
    ]);

    $emitted = [];
    $handled = (new WorkflowEngine)->handleTurn(
        $conv,
        'hi',
        function (string $text) use (&$emitted) {
            $emitted[] = $text;
        },
    );

    expect($handled)->toBeFalse();
    expect($emitted)->toBe([]);
});

// ─── Phase 2: branch + tag_lead + webhook + match_mode ──────────────────

test('branch step routes by var equality and falls through default', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['plan']],
        'definition' => [
            'steps' => [
                ['type' => 'question', 'text' => 'Free or Pro?', 'var_name' => 'choice'],
                ['type' => 'branch', 'var' => 'choice', 'cases' => [
                    ['match' => 'equals', 'value' => 'free', 'go_to' => 2],
                    ['match' => 'equals', 'value' => 'pro', 'go_to' => 4],
                    ['match' => 'default', 'go_to' => 6],
                ]],
                ['type' => 'message', 'text' => 'Free path.'],
                ['type' => 'escalate', 'text' => 'free done'],
                ['type' => 'message', 'text' => 'Pro path.'],
                ['type' => 'escalate', 'text' => 'pro done'],
                ['type' => 'message', 'text' => 'Default path.'],
                ['type' => 'escalate', 'text' => 'default done'],
            ],
        ],
    ]);

    $engine = new WorkflowEngine;
    $engine->handleTurn($conv, 'plan', function (string $t) {});

    $emitted = [];
    $engine->handleTurn($conv, 'pro', function (string $t) use (&$emitted) {
        $emitted[] = $t;
    });
    expect($emitted)->toBe(['Pro path.', 'pro done']);
});

test('branch matches contains / starts_with / not_empty', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['hi']],
        'definition' => [
            'steps' => [
                ['type' => 'question', 'text' => 'Tell me about your team', 'var_name' => 'team'],
                ['type' => 'branch', 'var' => 'team', 'cases' => [
                    ['match' => 'contains', 'value' => 'enterprise', 'go_to' => 2],
                    ['match' => 'starts_with', 'value' => 'small', 'go_to' => 4],
                    ['match' => 'not_empty', 'go_to' => 6],
                    ['match' => 'default', 'go_to' => 8],
                ]],
                ['type' => 'message', 'text' => 'Enterprise lane.'],
                ['type' => 'escalate', 'text' => 'e'],
                ['type' => 'message', 'text' => 'Small-team lane.'],
                ['type' => 'escalate', 'text' => 's'],
                ['type' => 'message', 'text' => 'Generic lane.'],
                ['type' => 'escalate', 'text' => 'g'],
                ['type' => 'message', 'text' => 'Empty lane.'],
                ['type' => 'escalate', 'text' => 'em'],
            ],
        ],
    ]);

    $engine = new WorkflowEngine;
    $engine->handleTurn($conv, 'hi', function (string $t) {});

    $emitted = [];
    $engine->handleTurn($conv, 'small startup with 4 people', function (string $t) use (&$emitted) {
        $emitted[] = $t;
    });
    expect($emitted[0])->toBe('Small-team lane.');
});

test('branch loop-guard fails the run after 32 jumps', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['loop']],
        'definition' => [
            'steps' => [
                // Two branches pointing at each other → loop forever without the guard.
                ['type' => 'branch', 'var' => 'x', 'cases' => [['match' => 'default', 'go_to' => 1]]],
                ['type' => 'branch', 'var' => 'x', 'cases' => [['match' => 'default', 'go_to' => 0]]],
            ],
        ],
    ]);

    (new WorkflowEngine)->handleTurn($conv, 'loop', function (string $t) {});

    $run = WorkflowRun::query()->where('conversation_id', $conv->id)->firstOrFail();
    expect($run->status)->toBe('failed');
});

test('tag_lead step appends tags to the conversation lead', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['tag']],
        'definition' => [
            'steps' => [
                ['type' => 'tag_lead', 'tags' => ['interested', 'pro_tier']],
                ['type' => 'message', 'text' => 'Tagged.'],
                ['type' => 'escalate', 'text' => 'done'],
            ],
        ],
    ]);

    $emitted = [];
    (new WorkflowEngine)->handleTurn($conv, 'please tag me', function (string $t) use (&$emitted) {
        $emitted[] = $t;
    });

    $lead = Lead::query()->withoutGlobalScopes()->where('conversation_id', $conv->id)->firstOrFail();
    expect($lead->fields['tags'])->toBe(['interested', 'pro_tier']);
    expect($emitted)->toContain('Tagged.');
});

test('webhook step dispatches DispatchWebhookJob with merged payload', function () {
    Bus::fake([DispatchWebhookJob::class]);

    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['ping']],
        'definition' => [
            'steps' => [
                ['type' => 'webhook', 'url' => 'https://example.com/hook', 'method' => 'POST', 'extra_payload' => ['source' => 'pitchbar']],
                ['type' => 'message', 'text' => 'Sent.'],
                ['type' => 'escalate', 'text' => 'done'],
            ],
        ],
    ]);

    (new WorkflowEngine)->handleTurn($conv, 'ping', function (string $t) {});

    Bus::assertDispatched(
        DispatchWebhookJob::class,
        function (DispatchWebhookJob $job) use ($conv) {
            return $job->url === 'https://example.com/hook'
                && $job->method === 'POST'
                && ($job->payload['source'] ?? null) === 'pitchbar'
                && ($job->payload['conversation_id'] ?? null) === $conv->id;
        },
    );
});

test('match_mode all requires every keyword to appear', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['enterprise', 'security'], 'match_mode' => 'all'],
        'definition' => [
            'steps' => [['type' => 'message', 'text' => 'matched']],
        ],
    ]);

    $engine = new WorkflowEngine;

    $emitted = [];
    $engine->handleTurn($conv, 'tell me about enterprise security', function (string $t) use (&$emitted) {
        $emitted[] = $t;
    });
    expect($emitted)->toBe(['matched']);

    // Reset by creating a new conversation since the run is now completed on $conv.
    ['conversation' => $conv2] = flowFixture();
    $emitted = [];
    $engine->handleTurn($conv2, 'just enterprise', function (string $t) use (&$emitted) {
        $emitted[] = $t;
    });
    expect($emitted)->toBe([]);  // missed: only one of two keywords appeared.
});

test('match_mode exact requires the message to equal one of the keywords verbatim', function () {
    ['workspace' => $workspace, 'agent' => $agent, 'conversation' => $conv] = flowFixture();
    Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'status' => 'active',
        'trigger_config' => ['keywords' => ['cancel'], 'match_mode' => 'exact'],
        'definition' => [
            'steps' => [['type' => 'message', 'text' => 'I'."'".'ll cancel that for you.']],
        ],
    ]);

    $engine = new WorkflowEngine;

    $emitted = [];
    // Exact, case-insensitive trim — should match.
    $engine->handleTurn($conv, '  Cancel ', function (string $t) use (&$emitted) {
        $emitted[] = $t;
    });
    expect(count($emitted))->toBeGreaterThan(0);

    // Substring — should NOT match in exact mode.
    ['conversation' => $conv2] = flowFixture();
    $emitted = [];
    $engine->handleTurn($conv2, 'I want to cancel my plan', function (string $t) use (&$emitted) {
        $emitted[] = $t;
    });
    expect($emitted)->toBe([]);
});
