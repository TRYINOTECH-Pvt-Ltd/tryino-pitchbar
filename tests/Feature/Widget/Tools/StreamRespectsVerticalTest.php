<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;

/**
 * End-to-end deep test: a visitor turn on a vertical-tagged agent must
 * land in the LLM with the preset's system fragment in messages[0].
 * We capture the FakeOpenAi call arguments and assert the contents.
 */
function makeVerticalConv(string $siteType): array
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

    return ['agent' => $agent, 'jwt' => $jwt['token']];
}

test('ecommerce stream sends e-commerce fragment to the LLM', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('It costs $49.');

    ['jwt' => $jwt] = makeVerticalConv('ecommerce');

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'how much is the widget?']);
    // Consume the stream so the StreamedResponse callback (and the
    // LLM call inside it) actually runs.
    $response->streamedContent();
    $response->assertOk();

    $lastCall = end($llm->chatCalls);
    $system = $lastCall['messages'][0]['content'];

    expect($system)->toContain('Vertical context (site type: ecommerce)');
    expect($system)->toContain('e-commerce store');
    expect($system)->toContain('Lead with the price');
});

test('documentation stream sends documentation fragment to the LLM', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Here is the example.');

    ['jwt' => $jwt] = makeVerticalConv('documentation');

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'how do I install it?']);
    $response->streamedContent();
    $response->assertOk();

    $system = end($llm->chatCalls)['messages'][0]['content'];
    expect($system)->toContain('Vertical context (site type: documentation)');
    expect($system)->toContain('technical documentation site');
    expect($system)->toContain('code examples');
});

test('agent without site_type sends NO vertical fragment', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Hi.');

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
        'allowed_origins' => ['https://example.com'],
        'site_type' => null,
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt['token']}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'q']);
    $response->streamedContent();
    $response->assertOk();

    $system = end($llm->chatCalls)['messages'][0]['content'];
    expect($system)->not->toContain('Vertical context');
});
