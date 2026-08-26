<?php

use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Rag\QueryRewriter;
use App\Services\Rag\RetrievalQueryBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * The condensed-standalone-question step. The gate keeps self-contained
 * questions on the LLM-free fast path; genuine follow-ups get a rewrite
 * when enabled, and any failure falls back to the deterministic heuristic.
 */
function qrStubLlm(string $reply, bool $throw = false): OpenAiClient
{
    return new class($reply, $throw) implements OpenAiClient
    {
        public int $calls = 0;

        public function __construct(private string $reply, private bool $throw) {}

        public function streamChat(array $messages, array $opts = []): iterable
        {
            return [];
        }

        public function chatWithTools(array $messages, array $tools, array $opts = []): array
        {
            $this->calls++;
            if ($this->throw) {
                throw new RuntimeException('provider down');
            }

            return ['content' => $this->reply, 'finish_reason' => 'stop'];
        }

        public function embed(array $inputs): array
        {
            return [];
        }
    };
}

function rewriter(OpenAiClient $llm): QueryRewriter
{
    return new QueryRewriter($llm, new RetrievalQueryBuilder);
}

function uTurn(string $c): array
{
    return ['role' => 'user', 'content' => $c];
}

function aTurn(string $c): array
{
    return ['role' => 'assistant', 'content' => $c];
}

beforeEach(function () {
    Cache::flush();
    config()->set('services.rag.query_rewrite.enabled', true);
});

it('returns a self-contained question verbatim WITHOUT calling the LLM (the gate)', function () {
    // "wat is uw adres" (4 words, not a fragment) is already a clean query —
    // this is the exact mid-conversation case that used to be polluted.
    $llm = qrStubLlm('SHOULD NOT BE USED');
    $out = rewriter($llm)->rewrite('wat is uw adres', [
        uTurn('Hoeveel kost een opslagbox per maand?'),
        aTurn('Een opslagbox kost €32 per maand.'),
    ], 'conv-1');

    expect($out)->toBe('wat is uw adres')
        ->and($llm->calls)->toBe(0);
});

it('returns a first-turn message verbatim (no prior context) without the LLM', function () {
    $llm = qrStubLlm('SHOULD NOT BE USED');
    $out = rewriter($llm)->rewrite('welk jaar?', [], 'conv-1');

    expect($out)->toBe('welk jaar?')
        ->and($llm->calls)->toBe(0);
});

it('rewrites a context-dependent follow-up via the LLM when enabled', function () {
    $llm = qrStubLlm('Jan Roel geboortejaar');
    $out = rewriter($llm)->rewrite('en het jaar?', [
        uTurn('Waar is Jan Roel geboren?'),
        aTurn('Jan Roel is geboren in Leeuwarden.'),
    ], 'conv-1');

    expect($out)->toBe('Jan Roel geboortejaar')
        ->and($llm->calls)->toBe(1);
});

it('falls back to the deterministic heuristic when the feature is disabled', function () {
    config()->set('services.rag.query_rewrite.enabled', false);
    $llm = qrStubLlm('SHOULD NOT BE USED');
    $out = rewriter($llm)->rewrite('en het jaar?', [uTurn('Waar is Jan Roel geboren?')], 'conv-1');

    // Heuristic stitch (leading interrogative stripped) — LLM untouched.
    expect($out)->toBe('is Jan Roel geboren? en het jaar?')
        ->and($llm->calls)->toBe(0);
});

it('falls back to the heuristic when the LLM errors', function () {
    $llm = qrStubLlm('', throw: true);
    $out = rewriter($llm)->rewrite('en het jaar?', [uTurn('Waar is Jan Roel geboren?')], 'conv-1');

    expect($out)->toBe('is Jan Roel geboren? en het jaar?')
        ->and($llm->calls)->toBe(1);
});

it('falls back to the heuristic when the LLM returns empty', function () {
    $llm = qrStubLlm('   ');
    $out = rewriter($llm)->rewrite('en het jaar?', [uTurn('Waar is Jan Roel geboren?')], 'conv-1');

    expect($out)->toBe('is Jan Roel geboren? en het jaar?');
});

it('sanitizes surrounding quotes and drops a trailing explanation', function () {
    $llm = qrStubLlm("\"Jan Roel geboortejaar\"\nExplanation: the user wants the birth year.");
    $out = rewriter($llm)->rewrite('en het jaar?', [uTurn('Waar is Jan Roel geboren?')], 'conv-1');

    expect($out)->toBe('Jan Roel geboortejaar');
});

it('caches the rewrite so a repeated message does not re-hit the LLM', function () {
    $llm = qrStubLlm('Jan Roel geboortejaar');
    $r = rewriter($llm);
    $history = [uTurn('Waar is Jan Roel geboren?')];

    $first = $r->rewrite('en het jaar?', $history, 'conv-1');
    $second = $r->rewrite('en het jaar?', $history, 'conv-1');

    expect($first)->toBe('Jan Roel geboortejaar')
        ->and($second)->toBe('Jan Roel geboortejaar')
        ->and($llm->calls)->toBe(1);
});

/**
 * Card #485 — the rewrite must never come out worse than the stitch.
 * Proven live on blengi: with the feature ON, #481's wider gate handed
 * control to the LLM and turns the stitch answered correctly went back
 * to deferring, because the model dropped the product name.
 */
test('a rewrite that drops the product name gets it back', function () {
    config(['services.rag.query_rewrite.enabled' => true]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    // What the small model actually does: a tidy, self-contained-looking
    // query with the subject quietly gone.
    $llm->pushToolFinalContent('Wat kost een sok in maat 39?');

    $query = app(QueryRewriter::class)->rewrite('Maat 39, wat kost dat ongeveer?', [
        ['role' => 'user', 'content' => 'Ik werk buiten in de bouw.'],
        ['role' => 'assistant', 'content' => 'Ik zou de Thermo Super aanbevelen. Wil je daar meer over weten?'],
    ], null);

    expect($query)->toContain('Thermo Super')
        ->and($query)->toContain('maat 39');
});

test('a rewrite that kept the subject is left exactly as the model wrote it', function () {
    config(['services.rag.query_rewrite.enabled' => true]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolFinalContent('Wat kost de Thermo Super in maat 39?');

    $query = app(QueryRewriter::class)->rewrite('Maat 39, wat kost dat ongeveer?', [
        ['role' => 'user', 'content' => 'Ik werk buiten in de bouw.'],
        ['role' => 'assistant', 'content' => 'Ik zou de Thermo Super aanbevelen. Wil je daar meer over weten?'],
    ], null);

    expect($query)->toBe('Wat kost de Thermo Super in maat 39?');
});

test('an empty rewrite still falls back to the deterministic stitch', function () {
    config(['services.rag.query_rewrite.enabled' => true]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->pushToolFinalContent('   ');

    $query = app(QueryRewriter::class)->rewrite('Maat 39, wat kost dat ongeveer?', [
        ['role' => 'user', 'content' => 'Ik werk buiten in de bouw.'],
        ['role' => 'assistant', 'content' => 'Ik zou de Thermo Super aanbevelen. Wil je daar meer over weten?'],
    ], null);

    expect($query)->toContain('Thermo Super')
        ->and($query)->toEndWith('Maat 39, wat kost dat ongeveer?');
});
