<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;

function pageContextConv(): array
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
        'page_url' => 'https://shop.example.com/products/macbook-air-m5',
    ]);
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt['token']];
}

test('streaming endpoint passes page_context into the LLM system message', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('Sure thing.');

    ['jwt' => $jwt] = pageContextConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'whats this product?',
            'page_context' => [
                'url' => 'https://shop.example.com/products/macbook-air-m5',
                'title' => 'MacBook Air M5 13-inch',
                'description' => "Apple's latest with M5 chip",
                'og' => ['type' => 'product', 'price:amount' => '999'],
                'json_ld' => [['@type' => 'Product', 'name' => 'MacBook Air M5']],
                'h1' => 'MacBook Air M5',
                'h2' => ['Performance'],
                'visible_text' => 'Apple MacBook Air with M5 chip ships in space gray.',
            ],
        ]);
    $response->assertOk();
    // Drain stream
    $response->streamedContent();

    expect($llm->chatCalls)->toHaveCount(1);

    $systemMessage = $llm->chatCalls[0]['messages'][0]['content'];
    expect($systemMessage)->toContain('type="current_page"');
    expect($systemMessage)->toContain('MacBook Air M5');
    expect($systemMessage)->toContain('Apple MacBook Air with M5 chip');
    expect($systemMessage)->toContain('Source [1] is a snapshot of THIS page');
});

test('streaming endpoint silently drops oversized page_context', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('OK.');

    ['jwt' => $jwt] = pageContextConv();

    // 12KB payload — should be rejected and the request should still succeed
    // without page_context flowing through.
    $oversized = str_repeat('x', 12000);

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'hi',
            'page_context' => ['title' => $oversized],
        ]);
    $response->assertOk();
    $response->streamedContent();

    $systemMessage = $llm->chatCalls[0]['messages'][0]['content'];
    expect($systemMessage)->not->toContain('type="current_page"');
    expect($systemMessage)->not->toContain($oversized);
});

test('streaming endpoint works without page_context (legacy widget compat)', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('Hi.');

    ['jwt' => $jwt] = pageContextConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'hi']);
    $response->assertOk();
    $response->streamedContent();

    $systemMessage = $llm->chatCalls[0]['messages'][0]['content'];
    expect($systemMessage)->not->toContain('type="current_page"');
});

test('streaming endpoint extracts citation [1] for page_context-only replies (no indexed chunks)', function () {
    // Reproducer for the "[1] not clickable in widget" bug — when retrieval
    // returns 0 chunks but page_context is provided, the LLM cites [1] but
    // citations array was empty (extractCitations used the un-augmented
    // sources). Now both the prompt and citations see source[0] = page_context.
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('The product name is Red Pen [1].');

    ['jwt' => $jwt] = pageContextConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'what is the product?',
            'page_context' => [
                'url' => 'https://shop.example.com/products/red-pen',
                'title' => 'Red Pen',
                'visible_text' => 'Red Pen — a versatile editing tool.',
            ],
        ]);
    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('event: done');
    // The done payload should include a citation pointing back at the page URL.
    expect($body)->toContain('"id":1');
    expect($body)->toContain('https://shop.example.com/products/red-pen');
});

test('citation URL is canonical — fragment + query stripped from page_context url', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('See [1].');

    ['jwt' => $jwt] = pageContextConv();

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'q',
            'page_context' => [
                // visitor scrolled to a specific section + arrived via UTM
                'url' => 'https://shop.example.com/products/red-pen/?utm_source=ig#description',
                'title' => 'Red Pen',
                'visible_text' => 'A red pen.',
            ],
        ]);
    $body = $response->streamedContent();

    // The citation URL should be canonical so it matches what
    // CrawlPageJob would have stored as Document.url.
    expect($body)->toContain('https://shop.example.com/products/red-pen');
    expect($body)->not->toContain('utm_source');
    expect($body)->not->toContain('#description');
});

test('confidence is page-context-baseline (0.85) when only page_context is provided', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('Answer grounded in page [1].');

    ['conv' => $conv, 'jwt' => $jwt] = pageContextConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'q',
            'page_context' => [
                'url' => 'https://shop.example.com/p',
                'title' => 'P',
                'visible_text' => 'real page content',
            ],
        ])->streamedContent();

    $assistantMsg = Message::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'assistant')
        ->latest('created_at')
        ->first();

    expect($assistantMsg)->not->toBeNull();
    expect((float) $assistantMsg->confidence)->toEqualWithDelta(0.85, 0.01);
});

test('confidence drops to 0.3 when neither retrieval nor page_context is available', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse("I'm not sure.");

    ['conv' => $conv, 'jwt' => $jwt] = pageContextConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'q'])
        ->streamedContent();

    $assistantMsg = Message::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'assistant')
        ->latest('created_at')
        ->first();

    expect($assistantMsg)->not->toBeNull();
    expect((float) $assistantMsg->confidence)->toEqualWithDelta(0.3, 0.01);
});

test('non-streaming /messages endpoint also accepts page_context', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushResponse('OK.');

    ['jwt' => $jwt] = pageContextConv();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages', [
            'message' => 'tell me about this',
            'page_context' => [
                'url' => 'https://shop.example.com/products/macbook-air-m5',
                'title' => 'MacBook Air M5',
                'visible_text' => 'Apple MacBook Air with M5 chip.',
            ],
        ])
        ->assertOk();

    $systemMessage = $llm->chatCalls[0]['messages'][0]['content'];
    expect($systemMessage)->toContain('type="current_page"');
    expect($systemMessage)->toContain('MacBook Air M5');
});
