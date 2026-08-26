<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;

function makeToolConv(string $siteType = 'help_center'): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
        'allowed_origins' => ['https://example.com'],
        'site_type' => $siteType,
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt['token']];
}

test('tool-enabled agent: stream emits tool_call + block + done', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);

    // Hop 1: model invokes escalate_to_human.
    $llm->pushToolCall('escalate_to_human', ['reason' => 'visitor needs help'], 'call_1');
    // Hop 2: model decides it's done — returns final content (this hop's
    // chatWithTools call returns 'content', so the loop exits and the
    // streamChat path takes over for the final answer).
    $llm->pushToolFinalContent('OK, connecting you.');
    // The streamChat call (final answer) uses the default response.
    $llm->setDefaultResponse('Connecting you with a human now.');

    ['jwt' => $jwt] = makeToolConv('help_center');

    // The visitor message must NOT match HumanIntentDetector — otherwise
    // the streaming controller short-circuits to the escalation_button
    // block before reaching the LLM tool loop. Use a generic problem
    // statement that the LLM should escalate via tool call.
    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'my account is broken']);

    $response->assertOk();
    $body = $response->streamedContent();

    // tool_call event fired with the tool name.
    expect($body)->toContain('event: tool_call');
    expect($body)->toContain('escalate_to_human');

    // block event with type=escalation_button.
    expect($body)->toContain('event: block');
    expect($body)->toContain('escalation_button');

    // Final stream + done.
    expect($body)->toContain('event: token');
    expect($body)->toContain('event: done');
    expect($body)->toContain('Connecting you with a human now.');

    // Keep-alive: each non-streaming tool hop emits a heartbeat so the
    // SSE connection never goes silent long enough for the widget's
    // stale-stream guard to abort the fetch on a slow tool turn.
    expect($body)->toContain(': heartbeat');

    // FakeOpenAi recorded the chatWithTools hop with the OpenAI tools
    // payload populated. v2.0.0 adds the `open_ticket` tool to the
    // help_center preset alongside the existing `escalate_to_human`.
    $toolNames = array_map(
        fn ($t) => $t['function']['name'],
        $llm->toolCalls[0]['tools'],
    );
    expect($llm->toolCalls)->not->toBe([]);
    expect($toolNames)
        ->toContain('escalate_to_human')
        ->toContain('open_ticket');
});

test('escalation_button is suppressed when conversation already offered one in the last 30 minutes', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);

    $llm->pushToolCall('escalate_to_human', ['reason' => 'second offer'], 'call_repeat');
    $llm->pushToolFinalContent('Still here.');
    $llm->setDefaultResponse('Reply two.');

    ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt] = makeToolConv('help_center');
    // Simulate the first offer 5 minutes ago — the suppression window
    // is 30 minutes, so this turn must NOT re-emit the button.
    $conv->forceFill([
        'attribution' => ['escalation_offered_at' => time() - 300],
        'human_requested_at' => null,
    ])->save();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'my account is still broken']);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('event: done');
    // The block event must NOT carry escalation_button this turn.
    expect($body)->not->toContain('"type":"escalation_button"');
});

test('escalation_button stamp expires after 30 minutes', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);

    $llm->pushToolCall('escalate_to_human', ['reason' => 'after expiry'], 'call_after');
    $llm->pushToolFinalContent('Refreshed.');
    $llm->setDefaultResponse('Reply three.');

    ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt] = makeToolConv('help_center');
    // Simulate the first offer 35 minutes ago — outside the window,
    // the button is allowed to re-emit.
    $conv->forceFill([
        'attribution' => ['escalation_offered_at' => time() - 35 * 60],
        'human_requested_at' => null,
    ])->save();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'i keep getting an error']);

    $response->assertOk();
    $body = $response->streamedContent();
    expect($body)->toContain('escalation_button');
});

test('agent with empty enabled_tools override skips the tool loop entirely', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('Hello.');

    // Every vertical now exposes escalate_to_human by default (operators
    // reported visitors couldn't reach a human off the help_center
    // preset). The operator can still narrow the tool list via
    // vertical_overrides.enabled_tools to opt out per agent.
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
        'allowed_origins' => ['https://example.com'],
        'site_type' => 'documentation',
        'vertical_overrides' => [
            'enabled_tools' => [],
        ],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id)['token'];

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Hi']);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->not->toContain('event: tool_call');
    expect($body)->toContain('Hello.');
    expect($llm->toolCalls)->toBe([]);
});
