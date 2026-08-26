<?php

use App\Jobs\Rag\PersistTurnTraceJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\TurnTrace;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiException;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

function traceConv(string $siteType = 'help_center'): array
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

function streamTraceTurn(string $jwt, string $message): void
{
    test()->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => $message])
        ->streamedContent(); // StreamedResponse is lazy — force the closure
}

it('persists an llm trace with route, retrieval, and tool-loop sections', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolFinalContent('Here is your answer.');
    $llm->setDefaultResponse('Here is your answer.');

    ['conv' => $conv, 'jwt' => $jwt] = traceConv();

    streamTraceTurn($jwt, 'what are your business hours');

    $trace = TurnTrace::query()->withoutWorkspaceScope()
        ->where('conversation_id', $conv->id)
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->kind)->toBe('llm')
        ->and($trace->payload['user_message'])->toBe('what are your business hours')
        ->and($trace->payload['answer'])->toBe('Here is your answer.')
        ->and($trace->payload)->toHaveKeys(['route', 'retrieval', 'tool_loop', 'history_count', 'latency_ms'])
        ->and($trace->payload['route']['route'])->toBe('tool_loop')
        ->and($trace->payload['route']['reason'])->toBe('disabled');
});

it('records tool hops with name, args, and result in the trace', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolCall('open_ticket', ['subject' => 'broken account']);
    $llm->pushToolFinalContent('Ticket created.');
    $llm->setDefaultResponse('Ticket created.');

    ['conv' => $conv, 'jwt' => $jwt] = traceConv();

    streamTraceTurn($jwt, 'please open a ticket, my account is broken');

    $trace = TurnTrace::query()->withoutWorkspaceScope()
        ->where('conversation_id', $conv->id)
        ->first();

    $hops = $trace->payload['tool_loop'];
    expect($hops)->toBeArray()->not->toBeEmpty();

    $executed = collect($hops)->firstWhere('outcome', 'executed');
    expect($executed)->not->toBeNull()
        ->and($executed['calls'][0]['name'])->toBe('open_ticket')
        ->and($executed['calls'][0]['args'])->toBe(['subject' => 'broken account'])
        ->and($executed['calls'][0])->toHaveKey('result');
});

it('persists an error trace when the LLM throws', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->failNextStreamWith(new OpenAiException('provider exploded'));

    ['conv' => $conv, 'jwt' => $jwt] = traceConv('generic');

    streamTraceTurn($jwt, 'anything at all');

    $trace = TurnTrace::query()->withoutWorkspaceScope()
        ->where('conversation_id', $conv->id)
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->kind)->toBe('error')
        ->and($trace->payload['error']['code'])->toBe('llm_failed')
        ->and($trace->payload['error']['message'])->toContain('provider exploded');
});

it('persists a human_shortcut trace when the visitor explicitly asks for a human', function () {
    ['conv' => $conv, 'jwt' => $jwt] = traceConv();

    streamTraceTurn($jwt, 'I want to talk to a human please');

    $trace = TurnTrace::query()->withoutWorkspaceScope()
        ->where('conversation_id', $conv->id)
        ->first();

    expect($trace)->not->toBeNull()
        ->and($trace->kind)->toBe('human_shortcut');
});

it('writes no trace when TURN_TRACES_ENABLED is off', function () {
    config(['services.turn_traces.enabled' => false]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolFinalContent('Answer.');
    $llm->setDefaultResponse('Answer.');

    ['conv' => $conv, 'jwt' => $jwt] = traceConv();

    streamTraceTurn($jwt, 'what are your business hours');

    expect(
        TurnTrace::query()->withoutWorkspaceScope()
            ->where('conversation_id', $conv->id)
            ->count(),
    )->toBe(0);
});

it('prunes traces older than the retention window', function () {
    ['agent' => $agent, 'conv' => $conv] = traceConv();

    TurnTrace::query()->create([
        'agent_id' => $agent->id,
        'conversation_id' => $conv->id,
        'message_id' => null,
        'kind' => 'llm',
        'payload' => ['user_message' => 'old'],
        'created_at' => now()->subDays(20),
    ]);
    TurnTrace::query()->create([
        'agent_id' => $agent->id,
        'conversation_id' => $conv->id,
        'message_id' => null,
        'kind' => 'llm',
        'payload' => ['user_message' => 'fresh'],
        'created_at' => now()->subDays(2),
    ]);

    $this->artisan('turn-traces:prune')->assertSuccessful();

    $remaining = TurnTrace::query()->withoutWorkspaceScope()->get();
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->payload['user_message'])->toBe('fresh');
});

it('stashes traces in cache when the table is missing and drains them on recovery', function () {
    ['agent' => $agent, 'conv' => $conv] = traceConv();

    // Outage: table gone (the real-world case — code deployed, migrate
    // run later). The job must cache the trace instead of losing it.
    Schema::dropIfExists('turn_traces');

    (new PersistTurnTraceJob(
        $agent->id, $conv->id, null, 'llm', ['user_message' => 'lost turn'],
    ))->handle();

    $backlog = Cache::get('turn_traces:fallback');
    expect($backlog)->toBeArray()->toHaveCount(1)
        ->and($backlog[0]['payload']['user_message'])->toBe('lost turn');

    // Recovery: table back (re-run the migration), next trace write
    // succeeds AND drains the backlog with its original timestamp.
    $migration = require database_path('migrations/2026_06_10_113941_create_turn_traces_table.php');
    $migration->up();

    (new PersistTurnTraceJob(
        $agent->id, $conv->id, null, 'llm', ['user_message' => 'fresh turn'],
    ))->handle();

    $rows = TurnTrace::query()->withoutWorkspaceScope()->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('payload.user_message')->sort()->values()->all())
        ->toBe(['fresh turn', 'lost turn'])
        ->and(Cache::get('turn_traces:fallback'))->toBeNull();
});
