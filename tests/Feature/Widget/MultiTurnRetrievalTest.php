<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Cache;

/**
 * Card #481 — end-to-end proof that a mid-conversation turn reaches
 * retrieval with its subject intact.
 *
 * The unit tests pin the string; this pins the wiring, because the bug
 * was invisible at the string level: every single-turn test passed
 * (a first message is self-contained by definition) while the real
 * conversations on stappsokken retrieved nothing from turn 2 onward.
 * The embedding input is the assertion — it is exactly what the vector
 * search sees.
 */
function multiTurnConv(array $history): array
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
    Cache::put("conv:{$conv->id}:history", $history, now()->addHour());
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);

    return ['agent' => $agent, 'conv' => $conv, 'jwt' => $jwt['token']];
}

function embeddedQuery(FakeOpenAi $llm): string
{
    return (string) ($llm->embedCalls[0][0] ?? '');
}

test('an answer to the assistant question is embedded with the conversation subject', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Ok.');

    ['jwt' => $jwt] = multiTurnConv([
        ['role' => 'user', 'content' => 'Hoi, ik zoek nieuwe sokken voor werk'],
        ['role' => 'assistant', 'content' => 'Waar draag je ze het meest — op kantoor of op de werkvloer?'],
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', [
            'message' => 'Hoge rand, ik draag werklaarzen. Ik werk buiten in de bouw en het is best koud.',
        ])->streamedContent();

    // Pre-fix this embedded the bare answer, which carries no product
    // noun at all — retrieval returned nothing and the agent deferred.
    expect(embeddedQuery($llm))
        ->toContain('sokken')
        ->toContain('werklaarzen');
});

test('the product the assistant just named survives into the next query', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Ok.');

    ['jwt' => $jwt] = multiTurnConv([
        ['role' => 'user', 'content' => 'Ik werk buiten in de bouw en het is koud.'],
        ['role' => 'assistant', 'content' => 'Ik zou de Thermo Super aanbevelen. Wil je daar meer over weten?'],
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Maat 39, wat kost dat ongeveer?'])
        ->streamedContent();

    // Without the product name the query is a generic price question, so
    // the size-availability chunk never competes — which is how the live
    // agent came to answer "€ 11,25, ongeacht de maat" for a size it does
    // not stock.
    expect(embeddedQuery($llm))
        ->toContain('Thermo Super')
        ->toContain('Maat 39');
});

test('a self-contained question mid-conversation is still embedded exactly as typed', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Ok.');

    ['jwt' => $jwt] = multiTurnConv([
        ['role' => 'user', 'content' => 'Hoeveel kost een opslagbox?'],
        ['role' => 'assistant', 'content' => 'Die kost €32 per maand. Wil je er een reserveren?'],
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'wat is uw adres'])
        ->streamedContent();

    expect(embeddedQuery($llm))->toBe('wat is uw adres');
});

test('a first message is unchanged — there is nothing to inherit', function () {
    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('Ok.');

    ['jwt' => $jwt] = multiTurnConv([]);

    $this->withHeaders(['Authorization' => "Bearer {$jwt}"])
        ->postJson('/api/v1/widget/messages/stream', ['message' => 'Hoi, ik zoek nieuwe sokken voor werk'])
        ->streamedContent();

    expect(embeddedQuery($llm))->toBe('Hoi, ik zoek nieuwe sokken voor werk');
});
