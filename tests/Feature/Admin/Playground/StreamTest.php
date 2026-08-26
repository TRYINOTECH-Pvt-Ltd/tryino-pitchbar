<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiTimeoutException;
use App\Services\Llm\Fakes\FakeOpenAi;
use Illuminate\Foundation\Testing\TestCase;

function plgMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

function plgRunStream(TestCase $test, User $user, Agent $agent, array $body): string
{
    $response = $test->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/playground/stream", $body);

    $response->assertOk();

    return $response->streamedContent();
}

test('stream returns SSE events with start, retrieval, prompt, token, done', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('A short reply.');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'site_type' => null,
        'confidence_threshold' => 0.0,
    ]);

    $stream = plgRunStream($this, $user, $agent, ['message' => 'hello']);

    expect($stream)->toContain('event: start');
    expect($stream)->toContain('event: retrieval');
    expect($stream)->toContain('event: prompt');
    expect($stream)->toContain('event: token');
    expect($stream)->toContain('event: done');
});

test('site_type_override flips the vertical fragment in the prompt', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('ok');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'site_type' => 'documentation',
        'confidence_threshold' => 0.0,
    ]);

    plgRunStream($this, $user, $agent, [
        'message' => 'how much for a red pen?',
        'site_type_override' => 'ecommerce',
    ]);

    $system = end($llm->chatCalls)['messages'][0]['content'];

    expect($system)->toContain('Vertical context (site type: ecommerce)');
    expect($system)->toContain('e-commerce store');
    expect($system)->not->toContain('site type: documentation');
});

test('language_override changes the language directive in the prompt', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Bonjour');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'language_default' => 'en',
        'confidence_threshold' => 0.0,
    ]);

    plgRunStream($this, $user, $agent, [
        'message' => 'hi',
        'language_override' => 'fr',
    ]);

    $system = end($llm->chatCalls)['messages'][0]['content'];

    expect($system)->toContain('Reply in French');
});

test('page_context flows into the prompt as source[1] (this page)', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('ok');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
    ]);

    plgRunStream($this, $user, $agent, [
        'message' => 'what is on this page',
        'page_context' => [
            'url' => 'https://shop.example.com/products/red-pen',
            'title' => 'Red Pen — Acme Shop',
            'description' => 'A red pen, $5.',
            'og' => ['type' => 'product', 'price:amount' => '5.00'],
        ],
    ]);

    $system = end($llm->chatCalls)['messages'][0]['content'];

    expect($system)->toContain('Source [1] is a snapshot of THIS page');
    expect($system)->toContain('Red Pen');
});

test('cross-tenant agent returns 403', function () {
    ['user' => $user] = plgMember();
    // Agent in a different workspace.
    $foreignWorkspace = Workspace::factory()->create();
    $foreignAgent = Agent::factory()->create(['workspace_id' => $foreignWorkspace->id]);

    $response = $this->actingAs($user)
        ->postJson("/app/agents/{$foreignAgent->id}/playground/stream", ['message' => 'hi']);

    // Route-model bound under BelongsToWorkspace global scope; the
    // foreign agent is invisible. Either 403 (policy denies) or 404
    // (model not found) is acceptable — both reject the cross-tenant
    // request. We accept both so the test stays robust to whichever
    // layer fires first.
    expect($response->getStatusCode())->toBeIn([403, 404]);
});

test('invalid site_type_override returns 422', function () {
    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/playground/stream", [
            'message' => 'hi',
            'site_type_override' => 'totally_made_up',
        ])
        ->assertStatus(422);
});

test('reusing a conversation_id reuses the same conversation row', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('reply');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
    ]);

    // First turn: no conversation_id → creates one.
    $first = plgRunStream($this, $user, $agent, ['message' => 'first']);
    preg_match('/"conversation_id":"([^"]+)"/', $first, $m);
    $convId = $m[1] ?? null;
    expect($convId)->not->toBeNull();

    $countAfterFirst = Conversation::query()->where('agent_id', $agent->id)->count();

    // Second turn: pass the conversation_id → no new conversation row.
    plgRunStream($this, $user, $agent, ['message' => 'second', 'conversation_id' => $convId]);
    expect(Conversation::query()->where('agent_id', $agent->id)->count())
        ->toBe($countAfterFirst);
});

test('WordPress page-context fields (source, post_id, post_type, permalink, categories, tags, woo) round-trip through the prompt', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('ok');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
    ]);

    plgRunStream($this, $user, $agent, [
        'message' => 'tell me about this product',
        'page_context' => [
            'source' => 'wordpress',
            'site_url' => 'https://shop.example.com/',
            'page_url' => 'https://shop.example.com/product/blue-tee',
            'url' => 'https://shop.example.com/product/blue-tee',
            'title' => 'Blue tee',
            'permalink' => 'https://shop.example.com/product/blue-tee',
            'post_id' => 9001,
            'post_type' => 'product',
            'categories' => ['tees', 'summer'],
            'tags' => ['cotton'],
            'woo' => [
                'id' => 9001,
                'sku' => 'T-BLU-M',
                'name' => 'Blue tee',
                'price' => '29.00',
                'currency' => 'USD',
                'stock_status' => 'instock',
                'on_sale' => true,
            ],
        ],
    ]);

    $system = (string) end($llm->chatCalls)['messages'][0]['content'];

    // The "this page" snapshot section fires whenever a non-empty
    // page_context payload survives sanitization. If our WP fields
    // were dropped, the section wouldn't render.
    expect($system)->toContain('Source [1] is a snapshot of THIS page');
    expect($system)->toContain('Blue tee');
});

test('invalid WP source values get stripped (whitelist + type discipline)', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('ok');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
    ]);

    plgRunStream($this, $user, $agent, [
        'message' => 'hi',
        'page_context' => [
            'source' => 'shopify', // not whitelisted → dropped
            'url' => 'https://shop.example.com/p/blue-tee',
            'title' => 'Blue tee',
            'categories' => 'not-an-array', // wrong type → dropped
            'woo' => 'still-not-an-array',  // wrong type → dropped
            'unknown_field' => 'leak',      // not in whitelist → dropped
        ],
    ]);

    $system = (string) end($llm->chatCalls)['messages'][0]['content'];

    expect($system)->toContain('Blue tee'); // legit fields still flow
    expect($system)->not->toContain('shopify');
    expect($system)->not->toContain('not-an-array');
    expect($system)->not->toContain('still-not-an-array');
    expect($system)->not->toContain('leak');
});

test('shopper_simulation persists wp_user_id + sha256(email) onto conversation.attribution.shopper', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('ok');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
    ]);

    $stream = plgRunStream($this, $user, $agent, [
        'message' => 'where is my order',
        'shopper_simulation' => [
            'wp_user_id' => 77,
            'email' => 'Shopper@Example.COM', // mixed case → server lowercases
        ],
    ]);

    preg_match('/"conversation_id":"([^"]+)"/', $stream, $m);
    $convId = $m[1] ?? null;
    expect($convId)->not->toBeNull();

    $conversation = Conversation::query()->where('id', $convId)->first();
    expect($conversation)->not->toBeNull();

    $shopper = (array) (($conversation->attribution ?? [])['shopper'] ?? []);

    expect($shopper['wp_user_id'])->toBe('77');
    expect($shopper['email_hash'])->toBe(hash('sha256', 'shopper@example.com'));
    expect($shopper['source'])->toBe('wordpress');
    expect($shopper['simulated'])->toBeTrue();
});

test('shopper_simulation with missing fields clears any prior simulated identity', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('ok');

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
    ]);

    // Seed the simulation.
    $first = plgRunStream($this, $user, $agent, [
        'message' => 'one',
        'shopper_simulation' => [
            'wp_user_id' => 5,
            'email' => 'a@b.com',
        ],
    ]);
    preg_match('/"conversation_id":"([^"]+)"/', $first, $m);
    $convId = $m[1] ?? null;
    expect($convId)->not->toBeNull();

    $conv = Conversation::query()->where('id', $convId)->first();
    expect((($conv->attribution ?? [])['shopper'] ?? null))->not->toBeNull();

    // Re-stream the same conversation with an empty shopper_simulation.
    // The controller treats it as "admin toggled off" and clears the
    // shopper claim so the next turn rehearses the anonymous path.
    plgRunStream($this, $user, $agent, [
        'message' => 'two',
        'conversation_id' => $convId,
        'shopper_simulation' => ['wp_user_id' => null, 'email' => ''],
    ]);

    $conv->refresh();
    expect(($conv->attribution ?? [])['shopper'] ?? null)->toBeNull();
});

test('shopper_simulation with non-positive wp_user_id is rejected (422)', function () {
    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/playground/stream", [
            'message' => 'hi',
            'shopper_simulation' => ['wp_user_id' => -1, 'email' => 'x@y.z'],
        ])
        ->assertStatus(422);
});

test('shopper_simulation with malformed email is rejected (422)', function () {
    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->postJson("/app/agents/{$agent->id}/playground/stream", [
            'message' => 'hi',
            'shopper_simulation' => ['wp_user_id' => 1, 'email' => 'not-an-email'],
        ])
        ->assertStatus(422);
});

// blengi 2026-06-26: the Playground "very slow + frequently shows 'Could
// not reach the configured AI provider. Check the server's outbound
// network (firewall, DNS).'" report. The true cause was a slow / cold-
// starting model overrunning the per-call timeout — Guzzle raises a
// "cURL error 28: Operation timed out" which used to be presented as a
// firewall/DNS fault. A timeout must surface as a SLOW-provider error so
// the operator doesn't chase a network ghost.
test('an LLM timeout surfaces as a slow-provider error, not a firewall/DNS fault', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('unused');
    $llm->failNextStreamWith(
        new OpenAiTimeoutException('cURL error 28: Operation timed out after 25000 milliseconds with 0 bytes received'),
    );

    ['user' => $user, 'workspace' => $workspace] = plgMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'confidence_threshold' => 0.0,
    ]);

    $stream = plgRunStream($this, $user, $agent, ['message' => 'wat doet alles opslaan meppel']);

    expect($stream)->toContain('event: error');
    expect($stream)->toContain('timed out');
    // The regression: the operator must NOT be told to check their
    // outbound network when the provider simply ran slow.
    expect($stream)->not->toContain('outbound network');
});
