<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function humanIntentConv(): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return [
        'agent' => $agent,
        'conv' => $conv,
        'jwt' => $jwt['token'],
    ];
}

test('explicit "talk to a human" message emits escalation_button + skips LLM', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    // Push a sentinel response that MUST NOT appear in the stream — if
    // the LLM is still called the test fails.
    $llm->pushResponse('SHOULD-NOT-APPEAR-FROM-LLM');

    ['jwt' => $jwt] = humanIntentConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'connect me to a human please',
        ]);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('event: block');
    expect($body)->toContain('"type":"escalation_button"');
    expect($body)->toContain('Connect me with a human');
    expect($body)->toContain('event: done');
    // LLM sentinel must NOT have been streamed — confirms the LLM
    // call was bypassed by the human-intent short-circuit.
    expect($body)->not->toContain('SHOULD-NOT-APPEAR-FROM-LLM');
});

test('non-intent messages still hit the LLM normally', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('Yes, our hours are 9–5 EST.');

    ['jwt' => $jwt] = humanIntentConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'when are you open',
        ]);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('Yes, our hours are 9');
    expect($body)->not->toContain('escalation_button');
});

test('intent short-circuit persists user + assistant messages', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('unused');

    ['conv' => $conv, 'jwt' => $jwt] = humanIntentConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'talk to a real person',
        ]);
    $response->assertOk();
    // Force the StreamedResponse closure to run — afterTurn() (which
    // PersistTurnJob::dispatchSync calls into) only fires once the
    // closure actually executes.
    $response->streamedContent();

    $persisted = Message::query()
        ->where('conversation_id', $conv->id)
        ->orderBy('created_at')
        ->get();

    expect($persisted)->toHaveCount(2);
    expect($persisted[0]->role)->toBe('user');
    expect($persisted[0]->content)->toBe('talk to a real person');
    expect($persisted[1]->role)->toBe('assistant');
    expect($persisted[1]->model)->toBe('human-intent');
});
