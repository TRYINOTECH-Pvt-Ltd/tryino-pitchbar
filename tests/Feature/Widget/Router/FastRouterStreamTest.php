<?php

use App\Jobs\Tools\WarmToolExemplarsJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Tools\Contracts\Tool;
use App\Services\Tools\ToolExemplarStore;
use App\Services\Tools\ToolRegistry;
use App\Services\Widget\WidgetJwt;
use App\Support\HotPathTimer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    config(['services.fast_router.enabled' => true]);
});

function routerConv(string $siteType = 'help_center'): array
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

test('knowledge question on a tool-enabled agent never calls chatWithTools', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('We are open 9 to 5 on weekdays.');

    ['jwt' => $jwt] = routerConv('help_center');

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'what are your business hours']);

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain('event: token')
        ->and($body)->toContain('event: done')
        // No tool-check completion happened at all.
        ->and($llm->toolCalls)->toBe([]);
});

test('keyword tool intent still runs the tool loop', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolCall('open_ticket', ['subject' => 'broken account'], 'call_1');
    $llm->pushToolFinalContent('Ticket opened.');
    $llm->setDefaultResponse('I have opened a ticket for you.');

    ['jwt' => $jwt] = routerConv('help_center');

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'please open a ticket, my account is broken']);

    $response->assertOk();
    $body = $response->streamedContent();
    expect($llm->toolCalls)->not->toBe([])
        ->and($body)->toContain('event: done');
});

test('embedding gate routes to the tool loop when query matches a warmed exemplar', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);

    // Warm the escalate centroid with an exemplar set whose centroid
    // is EXACTLY the embedding of the visitor message (FakeOpenAi
    // embeds deterministically: same text → same vector → cosine 1.0).
    $message = 'I am very unhappy can somebody assist me directly';
    $registry = app(ToolRegistry::class);
    $tool = $registry->get('escalate_to_human');

    $store = app(ToolExemplarStore::class);
    // Stub a tool-specific centroid: warm using the message itself by
    // writing the centroid straight into the store's cache key.
    $vector = $llm->embed([$message])[0];
    $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));
    $centroid = array_map(fn ($v) => $v / $norm, $vector);
    Cache::put($store->cacheKeyFor($tool), $centroid, now()->addDay());

    $llm->pushToolCall('escalate_to_human', ['reason' => 'visitor frustrated'], 'call_1');
    $llm->pushToolFinalContent('Connecting.');
    $llm->setDefaultResponse('Connecting you now.');

    ['jwt' => $jwt] = routerConv('help_center');

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => $message]);

    $response->assertOk();
    $response->streamedContent(); // force the lazy stream closure to run
    // chatWithTools ran → embedding gate routed tool_loop.
    expect($llm->toolCalls)->not->toBe([]);

    // Ring buffer recorded the route + reason.
    $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY, []);
    expect($recent[0]['extra']['route'] ?? null)->toBe('tool_loop')
        ->and($recent[0]['extra']['route_reason'] ?? null)->toBe('embedding');
});

test('cold exemplar cache dispatches a warm job once and never embeds inline', function () {
    Queue::fake();

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('General answer.');

    ['jwt' => $jwt, 'agent' => $agent, 'conv' => $conv] = routerConv('help_center');

    $embedCallsBefore = count($llm->embedCalls);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'tell me about your service tiers'])
        ->streamedContent();

    // One warm job per cold tool (help_center has escalate + open_ticket
    // + send_kb_article), deduped by lock on repeat turns.
    Queue::assertPushed(WarmToolExemplarsJob::class);
    $pushedFirst = collect(Queue::pushedJobs()[WarmToolExemplarsJob::class] ?? [])->count();

    // Second turn: locks held → no new jobs.
    $jwt2 = app(WidgetJwt::class)->issue($agent->id, $conv->visitor_id, $conv->id)['token'];
    $this->withHeaders(['Authorization' => "Bearer {$jwt2}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'and what about discounts for teams'])
        ->streamedContent();

    $pushedSecond = collect(Queue::pushedJobs()[WarmToolExemplarsJob::class] ?? [])->count();
    expect($pushedSecond)->toBe($pushedFirst);

    // Routing itself never embedded anything inline: the only embed
    // calls are the two retrieval query embeds.
    expect(count($llm->embedCalls) - $embedCallsBefore)->toBe(2);
});

test('router disabled restores legacy behavior (tool loop always runs)', function () {
    config(['services.fast_router.enabled' => false]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolFinalContent('No tool needed.');
    $llm->setDefaultResponse('Here is your answer.');

    ['jwt' => $jwt] = routerConv('help_center');

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'what are your business hours'])
        ->streamedContent();

    // chatWithTools ran even for a knowledge question — legacy path.
    expect($llm->toolCalls)->not->toBe([]);
});

test('per-agent opt-out beats the global flag', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolFinalContent('No tool needed.');
    $llm->setDefaultResponse('Answer.');

    ['jwt' => $jwt, 'agent' => $agent] = routerConv('help_center');
    $agent->forceFill([
        'vertical_overrides' => array_merge((array) $agent->vertical_overrides, ['fast_router' => false]),
    ])->save();

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'what are your business hours'])
        ->streamedContent();

    expect($llm->toolCalls)->not->toBe([]);
});

test('human-request shortcut still fires before the router', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('should never stream');

    ['jwt' => $jwt] = routerConv('help_center');

    $response = $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'I want to talk to a human please']);

    $response->assertOk();
    $body = $response->streamedContent();

    // Escalation block emitted by the shortcut; zero LLM involvement.
    expect($body)->toContain('escalation_button')
        ->and($llm->toolCalls)->toBe([])
        ->and($llm->chatCalls)->toBe([]);
});

test('stub tool with a namespaced name routes via description embedding', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);

    $stub = new class implements Tool
    {
        public function name(): string
        {
            return 'crm_lookup_contact';
        }

        public function description(): string
        {
            return 'Look up a contact record in the connected CRM by email address.';
        }

        public function capability(): string
        {
            return 'ticket_escalation'; // piggyback on help_center capability
        }

        public function schema(): array
        {
            return ['type' => 'object', 'properties' => []];
        }

        public function execute(array $args, Agent $agent, array $context = []): array
        {
            return ['ok' => true];
        }
    };

    app(ToolRegistry::class)->register($stub);

    // Centroid = embedding of the description (the HasIntentSignals
    // fallback) — query identical to description → cosine 1.0.
    app(ToolExemplarStore::class)->warm($stub);

    $llm->pushToolCall('crm_lookup_contact', [], 'call_1');
    $llm->pushToolFinalContent('Found.');
    $llm->setDefaultResponse('Contact found.');

    ['jwt' => $jwt] = routerConv('help_center');

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'Look up a contact record in the connected CRM by email address.',
        ])
        ->streamedContent();

    expect($llm->toolCalls)->not->toBe([]);

    $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY, []);
    expect($recent[0]['extra']['route'] ?? null)->toBe('tool_loop');
});

test('knowledge route is recorded in the ring buffer for the dashboard', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Plain answer.');

    ['jwt' => $jwt] = routerConv('help_center');

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'how do refunds work in general'])
        ->streamedContent();

    $recent = Cache::get(HotPathTimer::RECENT_CACHE_KEY, []);
    expect($recent[0]['extra']['route'] ?? null)->toBe('knowledge')
        ->and($recent[0]['extra']['route_reason'] ?? null)->toBeIn(['no_signal', 'no_embedding']);
});
