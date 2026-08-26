<?php

use App\Models\Agent;
use App\Models\ContentGap;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\DB;

function makeStreamConv(): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0, // make sure retrieval passes any chunk
        'allowed_origins' => ['https://example.com'],
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);

    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt['token']];
}

test('a content gap is recorded even when the analytics queue is never worked', function () {
    // Regression: gap-recording must not depend on the `analytics` queue
    // being drained. A production worker started without that queue in its
    // list would strand every DetectGapJob unrun and no deflected question
    // would ever reach Content Gaps. Simulate an unworked queue by pointing
    // the default connection at `database`: dispatched jobs persist to the
    // `jobs` table but nothing runs them. Gap-recording is dispatchSync, so
    // it runs inline on the `sync` connection regardless — a plain async
    // dispatch would leave the gap unwritten here.
    config(['queue.default' => 'database']);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    // A "don't know" reply trips looksLikeFailure → the turn is a gap.
    $llm->pushResponse("I don't know the answer to that — please contact the company.");

    ['agent' => $agent, 'jwt' => $jwt] = makeStreamConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}", 'Origin' => 'https://example.com'])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'What is your refund policy?'])
        ->assertOk()->streamedContent();

    // The gap row exists even though nothing drained the queue…
    expect(ContentGap::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())->toBe(1);
    // …and the still-async IncrementUsageJob really did pile up unrun on
    // the database queue, proving nothing was draining it.
    expect(DB::table('jobs')->count())->toBeGreaterThan(0);
});

test('streaming endpoint returns text/event-stream and yields token + done events', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('Hello there.');

    ['jwt' => $jwt] = makeStreamConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Hi']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    $body = $response->streamedContent();
    expect($body)->toContain('event: start');
    expect($body)->toContain('event: token');
    expect($body)->toContain('event: done');
    // Content of done event should include the assembled text
    expect($body)->toContain('Hello there.');
});

test('streaming endpoint emits stage events for searching and thinking before tokens', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('Hello there.');

    ['jwt' => $jwt] = makeStreamConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Hi']);

    $response->assertOk();

    $body = $response->streamedContent();

    // Both stage hints must appear so the widget's typing indicator
    // can swap copy through the pre-token window.
    expect($body)->toContain('event: stage');
    expect($body)->toContain('"s":"searching"');
    expect($body)->toContain('"s":"thinking"');

    // Both stages must land BEFORE the first token event — the widget
    // hides the indicator once tokens stream, so a late stage emit
    // would arrive after the indicator is already gone.
    $searchingPos = strpos($body, '"s":"searching"');
    $thinkingPos = strpos($body, '"s":"thinking"');
    $firstTokenPos = strpos($body, 'event: token');

    expect($searchingPos)->not->toBeFalse();
    expect($thinkingPos)->not->toBeFalse();
    expect($firstTokenPos)->not->toBeFalse();
    expect($searchingPos)->toBeLessThan($thinkingPos);
    expect($thinkingPos)->toBeLessThan($firstTokenPos);
});

test('streaming endpoint rejects missing token with 401', function () {
    $response = $this->postJson('/api/v1/widget/messages/stream', ['message' => 'Hi']);
    expect($response->getStatusCode())->toBe(401);
});

test('streaming endpoint rejects invalid token with 401', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer not-a-valid-jwt'])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Hi']);
    expect($response->getStatusCode())->toBe(401);
});
