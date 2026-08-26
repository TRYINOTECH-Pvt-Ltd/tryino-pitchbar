<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;

function makeHoldingConv(array $convOverrides = []): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(array_merge([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ], $convOverrides));
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt['token']];
}

test('stream returns holding bubble + human_pending=true when human_requested_at is set and unclaimed', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('REAL_LLM_REPLY_SHOULD_NOT_APPEAR');

    ['jwt' => $jwt] = makeHoldingConv([
        'human_requested_at' => now(),
        'claimed_by_user_id' => null,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'are you there']);

    $body = $response->streamedContent();
    $response->assertOk();

    expect($body)
        ->toContain('event: start')
        ->toContain('event: token')
        ->toContain('event: done')
        ->toContain('An operator is joining you in a moment')
        ->toContain('"human_pending":true');

    // The LLM must NOT have been called (no leakage of the default
    // reply text into the stream).
    expect($body)->not->toContain('REAL_LLM_REPLY_SHOULD_NOT_APPEAR');
    expect($llm->chatCalls)->toHaveCount(0);
});

test('claimed conversation still uses the existing takeover branch (not the holding bubble)', function () {
    // claimed_by_user_id non-null + human_requested_at non-null →
    // operator already arrived, so we go through the takeover branch
    // which emits an empty-text done with human_takeover=true. The
    // holding bubble branch must NOT fire here (it would double-render
    // the visitor's message in the operator's UI).
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);

    ['jwt' => $jwt] = makeHoldingConv([
        'human_requested_at' => now()->subMinutes(2),
        'claimed_by_user_id' => 1,
        'claimed_at' => now(),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'still there?']);
    $response->assertOk();

    $body = $response->streamedContent();
    expect($body)
        ->toContain('"human_takeover":true')
        ->not->toContain('An operator is joining you in a moment');
    expect($llm->chatCalls)->toHaveCount(0);
});

test('unflagged conversation runs the LLM normally', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Hi there!');

    ['jwt' => $jwt] = makeHoldingConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'hello']);
    $response->assertOk();

    $body = $response->streamedContent();
    expect($body)->toContain('Hi there!')
        ->not->toContain('An operator is joining you in a moment');
});
