<?php

use App\Services\Rag\RetrievalQueryBuilder;

/**
 * The builder widens a context-dependent follow-up with the previous
 * user question so retrieval embeds the entity + topic the follow-up
 * drops. Motivated by the client-reported birth-year bug: "En in welk
 * jaar?" embedded alone found nothing; stitched onto "Waar is Jan Roel
 * geboren?" it reaches the birth-year doc.
 */
function builder(): RetrievalQueryBuilder
{
    return new RetrievalQueryBuilder;
}

function userTurn(string $content): array
{
    return ['role' => 'user', 'content' => $content];
}

function assistantTurn(string $content): array
{
    return ['role' => 'assistant', 'content' => $content];
}

it('stitches the prior subject (interrogative stripped) onto a short Dutch follow-up', function () {
    // "Waar" is dropped so the prior question contributes its subject
    // ("Jan Roel geboren") without biasing retrieval toward a place —
    // otherwise the sibling-segment birth-year doc drops below threshold
    // (verified live 2026-07-05: keeping "Waar" answered place only).
    $q = builder()->build('En in welk jaar?', [
        userTurn('Waar is Jan Roel geboren?'),
        assistantTurn('Jan Roel is geboren in Leeuwarden.'),
    ]);

    expect($q)->toBe('is Jan Roel geboren? En in welk jaar?');
});

it('strips the leading interrogative from an English prior question', function () {
    $q = builder()->build('And in which year?', [
        userTurn('Where was Jan Roel born?'),
        assistantTurn('He was born in Leeuwarden.'),
    ]);

    expect($q)->toBe('was Jan Roel born? And in which year?');
});

it('leaves a non-interrogative prior question intact when stitching', function () {
    $q = builder()->build('And the price?', [
        userTurn('Tell me about the deluxe unit.'),
    ]);

    expect($q)->toBe('Tell me about the deluxe unit. And the price?');
});

it('leaves a self-contained question untouched', function () {
    $message = 'What are the opening hours of your storage location in Meppel?';
    $q = builder()->build($message, [
        userTurn('Do you have parking?'),
    ]);

    expect($q)->toBe($message);
});

it('does not stitch when there is no prior user turn', function () {
    // A short question, but the conversation just started — nothing to
    // lean on, so it embeds as-is (this is the standalone birth-year
    // case, which the Retriever fix handles cross-segment instead).
    $q = builder()->build('In welk jaar is Jan Roel geboren?', []);

    expect($q)->toBe('In welk jaar is Jan Roel geboren?');
});

it('reaches past the assistant turn to the most recent user message', function () {
    $q = builder()->build('How much?', [
        userTurn('Tell me about the deluxe storage unit.'),
        assistantTurn('The deluxe unit is 12 m³.'),
    ]);

    expect($q)->toBe('Tell me about the deluxe storage unit. How much?');
});

it('treats a long follow-up with a connective opener as context-dependent', function () {
    $q = builder()->build('And what about the price for the very large corner units?', [
        userTurn('Do you have large units?'),
    ]);

    expect($q)->toStartWith('Do you have large units? And what about the price');
});

it('caps a very long prior turn so it cannot drown the follow-up', function () {
    $long = str_repeat('storage ', 100); // ~800 chars
    $q = builder()->build('And the price?', [userTurn($long)]);

    expect(mb_strlen($q))->toBeLessThanOrEqual(320)
        ->and($q)->toEndWith('And the price?');
});

it('does NOT stitch a self-contained short question mid-conversation', function () {
    // The client-reported regression (proven live 2026-07-05): "wat is uw
    // adres" (4 words) answered as a first turn but deferred right after a
    // pricing question, because the old ≤7-word rule glued the pricing
    // subject onto it and the address dropped below threshold. A complete
    // short question must embed EXACTLY as typed.
    $q = builder()->build('wat is uw adres', [
        userTurn('Hoeveel kost een opslagbox van 6 m³ per maand?'),
        assistantTurn('Een opslagbox van 6 m³ kost €32 per maand.'),
    ]);

    expect($q)->toBe('wat is uw adres');
});

it('leaves other 4+ word self-contained questions exact regardless of history', function () {
    foreach (['waar zijn jullie gevestigd', 'what are your opening hours', 'wat is jullie telefoonnummer'] as $message) {
        $q = builder()->build($message, [userTurn('Tell me about the deluxe unit.')]);
        expect($q)->toBe($message);
    }
});

it('still stitches a bare ≤3-word fragment that has no subject of its own', function () {
    // "welk jaar?" carries no entity — it must still borrow the prior
    // subject, or the birth-year class of bug returns.
    $q = builder()->build('welk jaar?', [
        userTurn('Waar is Jan Roel geboren?'),
    ]);

    expect($q)->toBe('is Jan Roel geboren? welk jaar?');
});

it('ignores blank messages', function () {
    expect(builder()->build('   ', [userTurn('anything')]))->toBe('');
});

/**
 * Card #481 — the multi-turn gap. In Advisor Mode the visitor's turn is
 * usually an ANSWER to the assistant's question: long enough to look
 * self-contained, but carrying no product noun at all. Embedded as
 * typed it retrieved nothing (strict deferral) or loosely-related
 * chunks the model then dressed up as fact. Reproduced live on
 * stappsokken 2026-08-06.
 */
it('treats a long answer to the assistant question as context-dependent', function () {
    // Live turn 2. 14 words, opens on a content word — the old rule
    // called this self-contained and retrieval found nothing.
    $q = builder()->build('Hoge rand, ik draag werklaarzen. Ik werk buiten in de bouw en het is best koud.', [
        userTurn('Hoi, ik zoek nieuwe sokken voor werk'),
        assistantTurn('Prima startpunt! Waar draag je ze het meest — op kantoor of op de werkvloer?'),
    ]);

    expect($q)->toContain('sokken')
        ->and($q)->toEndWith('Ik werk buiten in de bouw en het is best koud.');
});

it('carries the product name from the assistant turn into the query', function () {
    // Live turn 3. The subject the visitor is answering about lives in
    // the ASSISTANT's turn — stitching only the prior user turn loses it,
    // so retrieval answered a generic price question and the model filled
    // the gap from conversation history ("ongeacht de maat").
    $q = builder()->build('Maat 39, wat kost dat ongeveer?', [
        userTurn('Ik werk buiten in de bouw en het is best koud.'),
        assistantTurn('Ik zou de Thermo Super aanbevelen. Wil je daar meer over weten?'),
    ]);

    expect($q)->toContain('Thermo Super')
        ->and($q)->toEndWith('Maat 39, wat kost dat ongeveer?');
});

it('does not mistake a sentence-opening capital for a product name', function () {
    $q = builder()->build('Ja graag, vertel maar meer daarover.', [
        userTurn('ik zoek sokken'),
        assistantTurn('Prima. Zal ik je wat opties laten zien?'),
    ]);

    expect($q)->not->toContain('Prima')
        ->and($q)->not->toContain('Zal');
});

it('still leaves a self-contained question exact right after an assistant question', function () {
    // The 2026-07-05 lesson must survive: a complete question that opens
    // with an interrogative is a topic switch, not an answer.
    $q = builder()->build('wat is uw adres', [
        userTurn('Hoeveel kost een opslagbox?'),
        assistantTurn('Een opslagbox kost €32 per maand. Wil je er een reserveren?'),
    ]);

    expect($q)->toBe('wat is uw adres');
});

it('leaves a long message alone when the assistant did not ask anything', function () {
    $message = 'Ik draag werklaarzen en werk buiten in de bouw.';
    $q = builder()->build($message, [
        userTurn('ik zoek sokken'),
        assistantTurn('Onze Thermo Super is gemaakt van 50% wol.'),
    ]);

    expect($q)->toBe($message);
});

it('reports an answer to the assistant question as context-dependent', function () {
    // The QueryRewriter checks this BEFORE its feature flag, so a false
    // here means the LLM rewrite never runs on exactly the messages that
    // need it most.
    $history = [
        userTurn('Hoi, ik zoek nieuwe sokken voor werk'),
        assistantTurn('Waar draag je ze het meest?'),
    ];

    expect(builder()->isContextDependent('Hoge rand, ik draag werklaarzen en werk buiten.', $history))->toBeTrue();
    expect(builder()->isContextDependent('wat is uw adres', $history))->toBeFalse();
});

it('caps how much assistant context it carries', function () {
    $assistant = 'Wij hebben de Thermo Super, de Boston Thermo, de Yellow Casual, de Walking Sok, '
        .'de Outdoor Coolmax en de Antistatische Sok. Welke spreekt je aan?';
    $q = builder()->build('De eerste twee klinken goed voor mijn werk buiten.', [
        userTurn('ik zoek werksokken'),
        assistantTurn($assistant),
    ]);

    expect(mb_strlen($q))->toBeLessThanOrEqual(500)
        ->and($q)->toEndWith('De eerste twee klinken goed voor mijn werk buiten.');
});
