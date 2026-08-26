<?php

use App\Models\Agent;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Tools\Contracts\HasIntentSignals;
use App\Services\Tools\Contracts\Tool;
use App\Services\Tools\RouteDecision;
use App\Services\Tools\ToolExemplarStore;
use App\Services\Tools\ToolIntentRouter;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['services.fast_router.enabled' => true]);
});

function routerAgent(array $overrides = []): Agent
{
    $workspace = Workspace::factory()->create();

    return Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'vertical_overrides' => $overrides,
    ]);
}

function stubTool(string $name, array $keywords = [], array $exemplars = []): Tool
{
    return new class($name, $keywords, $exemplars) implements HasIntentSignals, Tool
    {
        public function __construct(
            private string $toolName,
            private array $keywords,
            private array $exemplars,
        ) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return 'Stub tool '.$this->toolName;
        }

        public function capability(): string
        {
            return 'stub';
        }

        public function schema(): array
        {
            return ['type' => 'object'];
        }

        public function execute(array $args, Agent $agent, array $context = []): array
        {
            return [];
        }

        public function intentKeywords(): array
        {
            return $this->keywords;
        }

        public function intentExemplars(): array
        {
            return $this->exemplars;
        }
    };
}

it('routes knowledge when the agent has no tools', function () {
    $router = app(ToolIntentRouter::class);
    $decision = $router->route('hello', null, [], routerAgent());

    expect($decision->route)->toBe(RouteDecision::ROUTE_KNOWLEDGE)
        ->and($decision->reason)->toBe('no_tools');
});

it('routes tool_loop with reason disabled when the flag is off', function () {
    config(['services.fast_router.enabled' => false]);

    $router = app(ToolIntentRouter::class);
    $decision = $router->route('hello', null, [stubTool('t1')], routerAgent());

    expect($decision->route)->toBe(RouteDecision::ROUTE_TOOL_LOOP)
        ->and($decision->reason)->toBe('disabled')
        ->and($decision->matchedTools)->toBe(['t1']);
});

it('keyword gate fires case-insensitively', function () {
    $router = app(ToolIntentRouter::class);
    $tool = stubTool('orders', keywords: ['my order']);

    $decision = $router->route('Where is MY ORDER please', null, [$tool], routerAgent());

    expect($decision->route)->toBe(RouteDecision::ROUTE_TOOL_LOOP)
        ->and($decision->reason)->toBe('keyword')
        ->and($decision->matchedTools)->toBe(['orders']);
});

it('falls back to knowledge with no_embedding when query embedding is missing', function () {
    $router = app(ToolIntentRouter::class);
    $tool = stubTool('orders', keywords: ['my order']);

    $decision = $router->route('something unrelated', null, [$tool], routerAgent());

    expect($decision->route)->toBe(RouteDecision::ROUTE_KNOWLEDGE)
        ->and($decision->reason)->toBe('no_embedding');
});

it('embedding gate fires at exactly the threshold', function () {
    $llm = app(OpenAiClient::class);
    $store = app(ToolExemplarStore::class);
    $tool = stubTool('crm', exemplars: ['look up the contact in crm']);

    // Centroid = embedding of the message itself → cosine 1.0 ≥ any threshold.
    $message = 'look up the contact in crm';
    $vector = $llm->embed([$message])[0];
    $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));
    Cache::put(
        $store->cacheKeyFor($tool),
        array_map(fn ($v) => $v / $norm, $vector),
        now()->addDay(),
    );

    // 0.999 not 1.0: cosine of an identical vector lands at
    // 0.999999... after float accumulation — exact 1.0 is unreachable.
    config(['services.fast_router.threshold' => 0.999]);

    $router = app(ToolIntentRouter::class);
    $decision = $router->route($message, $vector, [$tool], routerAgent());

    expect($decision->route)->toBe(RouteDecision::ROUTE_TOOL_LOOP)
        ->and($decision->reason)->toBe('embedding')
        ->and($decision->topScore)->toBeGreaterThanOrEqual(0.999);
});

it('per-tool threshold override beats the default', function () {
    $llm = app(OpenAiClient::class);
    $store = app(ToolExemplarStore::class);
    $tool = stubTool('escalate_to_human', exemplars: ['talk to support']);

    $message = 'completely different text about pricing tiers';
    $exemplarVec = $llm->embed(['talk to support'])[0];
    $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $exemplarVec)));
    Cache::put(
        $store->cacheKeyFor($tool),
        array_map(fn ($v) => $v / $norm, $exemplarVec),
        now()->addDay(),
    );

    $queryVec = $llm->embed([$message])[0];

    // Default threshold impossible; per-tool override accepts anything.
    config([
        'services.fast_router.threshold' => 2.0,
        'services.fast_router.thresholds' => ['escalate_to_human' => -1.0],
    ]);

    $router = app(ToolIntentRouter::class);
    $decision = $router->route($message, $queryVec, [$tool], routerAgent());

    expect($decision->route)->toBe(RouteDecision::ROUTE_TOOL_LOOP)
        ->and($decision->matchedTools)->toBe(['escalate_to_human']);
});

it('skips tools whose centroid dimension mismatches the query', function () {
    $store = app(ToolExemplarStore::class);
    $tool = stubTool('crm', exemplars: ['anything']);

    // 3-dim centroid vs 1536-dim query → skipped, no crash.
    Cache::put($store->cacheKeyFor($tool), [1.0, 0.0, 0.0], now()->addDay());

    $queryVec = app(OpenAiClient::class)->embed(['some message'])[0];

    $router = app(ToolIntentRouter::class);
    $decision = $router->route('some message', $queryVec, [$tool], routerAgent());

    expect($decision->route)->toBe(RouteDecision::ROUTE_KNOWLEDGE)
        ->and($decision->reason)->toBe('no_signal');
});

it('per-agent override enables routing even when global flag is off', function () {
    config(['services.fast_router.enabled' => false]);

    $router = app(ToolIntentRouter::class);
    $agent = routerAgent(['fast_router' => true]);
    $tool = stubTool('t1');

    // Enabled per-agent → no keyword, no embedding → knowledge.
    $decision = $router->route('plain question', null, [$tool], $agent);

    expect($decision->route)->toBe(RouteDecision::ROUTE_KNOWLEDGE);
});
