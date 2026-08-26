<?php

use App\Models\WidgetEvent;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiBadRequestException;
use App\Services\Llm\Exceptions\OpenAiException;
use App\Services\Llm\Exceptions\OpenAiTimeoutException;
use App\Services\Llm\FailoverOpenAiClient;
use App\Services\Widget\WidgetEventRecorder;

/**
 * A configurable in-memory OpenAiClient. Each result may be a value (returned)
 * or a Throwable (thrown). For streamChat, a Closure lets us yield some tokens
 * and THEN throw, to exercise the post-first-token path.
 */
function stubLlm(mixed $stream = null, mixed $tool = null, mixed $embed = null): OpenAiClient
{
    return new class($stream, $tool, $embed) implements OpenAiClient
    {
        public function __construct(private mixed $stream, private mixed $tool, private mixed $embed) {}

        public function streamChat(array $messages, array $opts = []): iterable
        {
            $r = $this->stream;
            if ($r instanceof Closure) {
                yield from $r();

                return;
            }
            if ($r instanceof Throwable) {
                throw $r;
            }
            yield from (is_iterable($r) ? $r : []);
        }

        public function chatWithTools(array $messages, array $tools, array $opts = []): array
        {
            if ($this->tool instanceof Throwable) {
                throw $this->tool;
            }

            return is_array($this->tool) ? $this->tool : ['content' => 'ok', 'finish_reason' => 'stop'];
        }

        public function embed(array $inputs): array
        {
            if ($this->embed instanceof Throwable) {
                throw $this->embed;
            }

            return is_array($this->embed) ? $this->embed : [[0.1, 0.2]];
        }
    };
}

/**
 * Spy recorder: captures record() calls in memory instead of dispatching a
 * job — keeps this a pure unit test (no DB, no Queue, no app boot).
 *
 * @return WidgetEventRecorder&object{events: array<int, array<string, mixed>>}
 */
function spyRecorder(): WidgetEventRecorder
{
    return new class extends WidgetEventRecorder
    {
        /** @var array<int, array<string, mixed>> */
        public array $events = [];

        public function record(
            string $type,
            string $severity = WidgetEvent::SEVERITY_ERROR,
            ?string $provider = null,
            ?string $message = null,
            array $context = [],
            ?string $workspaceId = null,
            ?string $agentId = null,
            ?string $conversationId = null,
        ): void {
            $this->events[] = ['type' => $type, 'provider' => $provider, 'severity' => $severity];
        }
    };
}

function failover(WidgetEventRecorder $recorder, OpenAiClient $primary, OpenAiClient $fallback): FailoverOpenAiClient
{
    return new FailoverOpenAiClient([
        ['name' => 'cloudflare', 'client' => $primary],
        ['name' => 'openai', 'client' => $fallback],
    ], $recorder);
}

it('fails over chatWithTools to the next provider on a retryable error', function () {
    $recorder = spyRecorder();
    $client = failover(
        $recorder,
        stubLlm(tool: new OpenAiTimeoutException('cloudflare hung')),
        stubLlm(tool: ['content' => 'from openai', 'finish_reason' => 'stop']),
    );

    $result = $client->chatWithTools([], []);

    expect($result['content'])->toBe('from openai');
    expect($recorder->events)->toHaveCount(1);
    expect($recorder->events[0]['type'])->toBe(WidgetEventRecorder::TYPE_PROVIDER_FAILOVER);
    expect($recorder->events[0]['provider'])->toBe('cloudflare');
});

it('does NOT fail over chatWithTools on a 4xx bad request', function () {
    $recorder = spyRecorder();
    $client = failover(
        $recorder,
        stubLlm(tool: new OpenAiBadRequestException('malformed request')),
        stubLlm(tool: ['content' => 'should never be used', 'finish_reason' => 'stop']),
    );

    // A 4xx is our fault — every provider rejects it identically, so we must
    // surface it immediately, not waste a second provider on it.
    expect(fn () => $client->chatWithTools([], []))->toThrow(OpenAiBadRequestException::class);
    expect($recorder->events)->toBeEmpty();
});

it('fails over embed to the next provider', function () {
    $recorder = spyRecorder();
    $client = failover(
        $recorder,
        stubLlm(embed: new OpenAiException('cloudflare 503')),
        stubLlm(embed: [[0.9, 0.8]]),
    );

    expect($client->embed(['hi']))->toBe([[0.9, 0.8]]);
    expect($recorder->events[0]['type'])->toBe(WidgetEventRecorder::TYPE_PROVIDER_FAILOVER);
});

it('fails over streamChat BEFORE the first token is yielded', function () {
    $recorder = spyRecorder();
    $client = failover(
        $recorder,
        stubLlm(stream: new OpenAiException('cloudflare 520')),
        stubLlm(stream: ['he', 'llo']),
    );

    $out = '';
    foreach ($client->streamChat([]) as $token) {
        $out .= $token;
    }

    expect($out)->toBe('hello');
    expect($recorder->events[0]['type'])->toBe(WidgetEventRecorder::TYPE_PROVIDER_FAILOVER);
});

it('does NOT fail over streamChat once a token has been yielded — the error propagates', function () {
    $recorder = spyRecorder();
    $client = failover(
        $recorder,
        stubLlm(stream: function () {
            yield 'par';
            // Connection dies mid-answer — the visitor has already seen "par",
            // so we cannot restart on another provider.
            throw new OpenAiException('mid-stream reset');
        }),
        stubLlm(stream: ['SHOULD-NOT-APPEAR']),
    );

    $out = '';
    $threw = false;
    try {
        foreach ($client->streamChat([]) as $token) {
            $out .= $token;
        }
    } catch (OpenAiException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect($out)->toBe('par');
    expect($out)->not->toContain('SHOULD-NOT-APPEAR');
    // No failover happened — we were already past the first token.
    expect($recorder->events)->toBeEmpty();
});

it('records provider_down and rethrows when every provider is exhausted', function () {
    $recorder = spyRecorder();
    $client = failover(
        $recorder,
        stubLlm(tool: new OpenAiTimeoutException('cloudflare hung')),
        stubLlm(tool: new OpenAiException('openai down too')),
    );

    expect(fn () => $client->chatWithTools([], []))->toThrow(OpenAiException::class);

    $types = array_column($recorder->events, 'type');
    expect($types)->toContain(WidgetEventRecorder::TYPE_PROVIDER_FAILOVER); // cloudflare → openai
    expect($types)->toContain(WidgetEventRecorder::TYPE_PROVIDER_DOWN);     // openai exhausted
});

it('self-heals Cloudflare model→model when the primary model is slow', function () {
    // The single-Cloudflare self-heal path: both entries are Workers AI,
    // differing only by chat model. The primary (e.g. llama-3.3-70b)
    // times out cold-starting; the decorator transparently switches to
    // the faster fallback model (llama-3.1-8b) — no second provider.
    $recorder = spyRecorder();
    $client = new FailoverOpenAiClient([
        ['name' => 'cloudflare', 'client' => stubLlm(stream: new OpenAiTimeoutException('cURL error 28: Operation timed out'))],
        ['name' => 'cloudflare-fallback', 'client' => stubLlm(stream: ['fast ', 'answer'])],
    ], $recorder);

    $out = '';
    foreach ($client->streamChat([]) as $token) {
        $out .= $token;
    }

    expect($out)->toBe('fast answer');
    expect($recorder->events[0]['type'])->toBe(WidgetEventRecorder::TYPE_PROVIDER_FAILOVER);
    expect($recorder->events[0]['provider'])->toBe('cloudflare');
});

it('rethrows immediately with a single provider and no fallback', function () {
    $recorder = spyRecorder();
    $client = new FailoverOpenAiClient([
        ['name' => 'cloudflare', 'client' => stubLlm(tool: new OpenAiTimeoutException('hung'))],
    ], $recorder);

    expect(fn () => $client->chatWithTools([], []))->toThrow(OpenAiTimeoutException::class);
    // Single provider that's down IS a provider_down event (nothing to fall back to).
    expect($recorder->events[0]['type'])->toBe(WidgetEventRecorder::TYPE_PROVIDER_DOWN);
});
