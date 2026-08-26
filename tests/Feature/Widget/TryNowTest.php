<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\TryNow\TryNowSession;
use Illuminate\Support\Facades\Http;

/**
 * Anonymous try-now demo on the Prism marketing hero. Visitor pastes
 * a URL, server fetches it once, caches chunks, and the visitor can
 * chat against the cached content. No agent, no workspace, no DB.
 */
beforeEach(function () {
    app()->instance(OpenAiClient::class, new FakeOpenAi);
});

test('start endpoint ingests a URL and returns a token', function () {
    Http::fake([
        '*' => Http::response(
            '<html><head><title>Acme Pricing</title></head><body>'
                .'<p>Acme offers three pricing tiers — starter, pro, and enterprise.</p>'
                .'<p>The starter tier is free for the first 1,000 messages a month.</p>'
                .'<p>Pro is $49/month and includes unlimited messages and lead capture.</p>'
                .'</body></html>',
            200,
        ),
    ]);

    $response = $this->postJson('/api/v1/widget/try-now', [
        'url' => 'https://acme.example/pricing',
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => ['token', 'title', 'summary', 'page_url', 'initial_message'],
    ]);
    expect($response->json('data.title'))->toContain('Acme');
    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

test('start endpoint 422s when the URL returns a non-2xx', function () {
    Http::fake([
        '*' => Http::response('', 404),
    ]);

    $response = $this->postJson('/api/v1/widget/try-now', [
        'url' => 'https://broken.example/page',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('try_now_fetch_failed');
});

test('start endpoint 422s when the page has no readable text', function () {
    Http::fake([
        '*' => Http::response('<html><body><nav>menu</nav></body></html>', 200),
    ]);

    $response = $this->postJson('/api/v1/widget/try-now', [
        'url' => 'https://empty.example/page',
    ]);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('try_now_fetch_failed');
});

test('start endpoint validates url is required', function () {
    $response = $this->postJson('/api/v1/widget/try-now', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['url']);
});

test('start endpoint normalizes bare hosts to https', function () {
    Http::fake([
        'https://example.test/*' => Http::response(
            '<html><head><title>Example</title></head><body>'
                .'<p>The quick brown fox jumps over the lazy dog. '
                .'This page has plenty of readable copy for the chunker.</p>'
                .'</body></html>',
            200,
        ),
        '*' => Http::response('', 404),
    ]);

    $response = $this->postJson('/api/v1/widget/try-now', [
        'url' => 'example.test/page',
    ]);

    $response->assertOk();
    expect($response->json('data.page_url'))->toStartWith('https://');
});

test('stream endpoint returns 200 and streams tokens for a valid token', function () {
    Http::fake([
        '*' => Http::response(
            '<html><head><title>Acme</title></head><body>'
                .'<p>Acme sells widgets. Acme has 100 customers. Acme is great. '
                .'Acme ships worldwide overnight via FedEx and DHL on weekdays.</p>'
                .'</body></html>',
            200,
        ),
    ]);

    /** @var FakeOpenAi $fake */
    $fake = app(OpenAiClient::class);
    $fake->pushResponse('Acme sells widgets.');

    $start = $this->postJson('/api/v1/widget/try-now', [
        'url' => 'https://acme.example',
    ])->assertOk();

    $token = $start->json('data.token');

    $response = $this->postJson('/api/v1/widget/try-now/stream', [
        'token' => $token,
        'message' => 'What does Acme sell?',
    ]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    $body = $response->streamedContent();
    expect($body)->toContain('event: token');
    expect($body)->toContain('event: done');
});

test('stream endpoint emits an error event when the token is unknown', function () {
    $response = $this->postJson('/api/v1/widget/try-now/stream', [
        'token' => 'definitely-not-real',
        'message' => 'hello?',
    ]);

    $response->assertOk();
    $body = $response->streamedContent();
    expect($body)->toContain('event: error');
    expect($body)->toContain('try_now_expired');
});

test('stream endpoint validates message length', function () {
    $response = $this->postJson('/api/v1/widget/try-now/stream', [
        'token' => 'whatever',
        'message' => str_repeat('a', 1500),
    ]);

    $response->assertStatus(422);
});

test('stream endpoint embeds the URL page chunks into the system prompt', function () {
    Http::fake([
        '*' => Http::response(
            '<html><head><title>UniqueTitle</title></head><body>'
                .'<p>The product is called UniqueWidget. It costs 42 dollars. '
                .'It ships in three business days from our Brooklyn warehouse.</p>'
                .'</body></html>',
            200,
        ),
    ]);

    /** @var FakeOpenAi $fake */
    $fake = app(OpenAiClient::class);
    $fake->pushResponse('Reply.');

    $start = $this->postJson('/api/v1/widget/try-now', [
        'url' => 'https://unique.example',
    ]);
    $token = $start->json('data.token');

    $this->postJson('/api/v1/widget/try-now/stream', [
        'token' => $token,
        'message' => 'What is the product called?',
    ])->streamedContent();

    $call = $fake->chatCalls[0] ?? null;
    expect($call)->not->toBeNull();
    $system = $call['messages'][0]['content'] ?? '';
    expect($system)->toContain('UniqueWidget');
    expect($system)->toContain('<source');
});

test('try-now does not touch tenant data', function () {
    Http::fake([
        '*' => Http::response(
            '<html><head><title>X</title></head><body>'
                .'<p>This is some content with enough text to chunk and demo. '
                .'It has multiple sentences to satisfy minimum length.</p>'
                .'</body></html>',
            200,
        ),
    ]);

    $beforeAgents = Agent::query()->withoutGlobalScopes()->count();
    $beforeConvs = Conversation::query()->withoutGlobalScopes()->count();

    $this->postJson('/api/v1/widget/try-now', [
        'url' => 'https://acme.example',
    ])->assertOk();

    expect(Agent::query()->withoutGlobalScopes()->count())->toBe($beforeAgents);
    expect(Conversation::query()->withoutGlobalScopes()->count())->toBe($beforeConvs);
});

test('start endpoint falls back to meta + Inertia JSON for SPA pages with empty bodies', function () {
    Http::fake([
        '*' => Http::response(
            '<!DOCTYPE html><html><head>'
                .'<title>Acme Pricing</title>'
                .'<meta name="description" content="Acme pricing: free tier, pro at $49/month, and enterprise with SSO. Cancel anytime, transparent per-seat billing.">'
                .'<meta property="og:title" content="Pricing — Acme">'
                .'<meta property="og:description" content="Free to start, pro at $49/month, enterprise with SSO.">'
                .'</head><body>'
                .'<div id="app"></div>'
                .'<script data-page="app" type="application/json">'
                .'{"component":"pricing","props":{"plans":[{"name":"Free","blurb":"For solo developers who want to try Acme out without entering a credit card."},{"name":"Pro","blurb":"Includes unlimited seats and priority support for growing teams."}]}}'
                .'</script>'
                .'</body></html>',
            200,
        ),
    ]);

    $response = $this->postJson('/api/v1/widget/try-now', [
        'url' => 'https://acme.example/pricing',
    ]);

    $response->assertOk();
    expect($response->json('data.title'))->toContain('Acme');
    expect($response->json('data.summary'))->toContain('pricing');
});

test('TryNowSession caches under a unique token per call', function () {
    Http::fake([
        '*' => Http::response(
            '<html><head><title>Y</title></head><body>'
                .'<p>Some readable text that is long enough to pass the minimum threshold. '
                .'Multiple sentences here so the chunker has material.</p>'
                .'</body></html>',
            200,
        ),
    ]);

    $service = app(TryNowSession::class);
    $a = $service->start('https://a.example');
    $b = $service->start('https://b.example');

    expect($a['token'])->not->toBe($b['token']);
    expect($service->get($a['token']))->not->toBeNull();
    expect($service->get($b['token']))->not->toBeNull();
});
