<?php

namespace App\Services\Llm;

use App\Models\WidgetEvent;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiBadRequestException;
use App\Services\Llm\Exceptions\OpenAiException;
use App\Services\Widget\WidgetEventRecorder;

/**
 * Runtime failover across an ordered list of LLM providers.
 *
 * The single biggest widget-reliability lever. Without it, ONE provider
 * (Cloudflare on most installs) is bound at boot and a single outage — slow,
 * 5xx, 429, out of credits — kills EVERY visitor turn ("chat stopped
 * unexpectedly"). With it, the decorator transparently retries the next
 * configured provider, so a single-provider hiccup becomes invisible to the
 * visitor.
 *
 * Retry policy (per call):
 *   - Retryable: any OpenAiException EXCEPT OpenAiBadRequestException
 *     (timeouts, 429s, 5xx, connection failures → the provider is the
 *     problem; another provider may succeed).
 *   - NOT retryable: OpenAiBadRequestException (a 4xx — OUR request is
 *     malformed; every provider rejects it identically, so failing over just
 *     doubles the latency and the error). Re-thrown immediately.
 *   - NOT retryable: any non-OpenAiException Throwable (a bug, not an outage)
 *     — propagates untouched.
 *
 * Streaming caveat: streamChat can ONLY fail over BEFORE the first token is
 * yielded. Once the visitor has seen text we cannot restart on another
 * provider, so a mid-stream failure propagates (the controller's top-level
 * catch surfaces it). Workers AI / OpenAI throw their status-code error
 * lazily on the first generator iteration — before the first yield — which is
 * exactly the window this exploits.
 *
 * Octane-safe: stateless apart from the immutable provider list built in the
 * per-request scoped binding; no container/request captured.
 */
class FailoverOpenAiClient implements OpenAiClient
{
    /** @var array<int, array{name: string, client: OpenAiClient}> */
    private array $providers;

    /**
     * @param  array<int, array{name: string, client: OpenAiClient}>  $providers  Ordered, primary first; must hold ≥1.
     */
    public function __construct(
        array $providers,
        private readonly WidgetEventRecorder $recorder,
    ) {
        $this->providers = array_values($providers);
    }

    public function streamChat(array $messages, array $opts = []): iterable
    {
        $lastIndex = count($this->providers) - 1;

        foreach ($this->providers as $i => $provider) {
            $isLast = $i === $lastIndex;

            try {
                $iterator = $this->toIterator($provider['client']->streamChat($messages, $opts));
                // Triggers the HTTP request + status check. Workers AI /
                // OpenAI throw their *Exception HERE, before the first token
                // — the seam where failover is still safe.
                $iterator->rewind();
                $valid = $iterator->valid();
            } catch (OpenAiException $e) {
                if ($this->shouldStop($e, $isLast)) {
                    $this->recordExhausted('streamChat', $provider['name'], $e, $isLast);
                    throw $e;
                }
                $this->recordFailover('streamChat', $provider['name'], $this->providers[$i + 1]['name'], $e);

                continue;
            }

            // First token (if any) arrived — commit to this provider. Any
            // failure from here is post-first-token: it propagates, because
            // we can't swap providers once the visitor sees output.
            while ($valid) {
                yield $iterator->current();
                $iterator->next();
                $valid = $iterator->valid();
            }

            return;
        }
    }

    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        $lastIndex = count($this->providers) - 1;

        foreach ($this->providers as $i => $provider) {
            $isLast = $i === $lastIndex;

            try {
                return $provider['client']->chatWithTools($messages, $tools, $opts);
            } catch (OpenAiException $e) {
                if ($this->shouldStop($e, $isLast)) {
                    $this->recordExhausted('chatWithTools', $provider['name'], $e, $isLast);
                    throw $e;
                }
                $this->recordFailover('chatWithTools', $provider['name'], $this->providers[$i + 1]['name'], $e);
            }
        }

        // Unreachable: the list is non-empty and the last provider either
        // returns or throws above. Satisfies the return type.
        throw new OpenAiException('No LLM provider produced a response.');
    }

    public function embed(array $inputs): array
    {
        $lastIndex = count($this->providers) - 1;

        foreach ($this->providers as $i => $provider) {
            $isLast = $i === $lastIndex;

            try {
                return $provider['client']->embed($inputs);
            } catch (OpenAiException $e) {
                if ($this->shouldStop($e, $isLast)) {
                    $this->recordExhausted('embed', $provider['name'], $e, $isLast);
                    throw $e;
                }
                $this->recordFailover('embed', $provider['name'], $this->providers[$i + 1]['name'], $e);
            }
        }

        throw new OpenAiException('No LLM provider produced an embedding.');
    }

    /**
     * Stop trying further providers when the error is our fault (a 4xx that
     * every provider rejects identically) or there's no fallback left.
     */
    private function shouldStop(OpenAiException $e, bool $isLast): bool
    {
        return $e instanceof OpenAiBadRequestException || $isLast;
    }

    /**
     * Coerce streamChat's `iterable` return into a rewindable Iterator
     * without eagerly consuming a real stream (Generators already implement
     * Iterator; IteratorAggregate is wrapped lazily; arrays are buffered).
     *
     * @param  iterable<int, string>  $iter
     * @return \Iterator<int, string>
     */
    private function toIterator(iterable $iter): \Iterator
    {
        if ($iter instanceof \Iterator) {
            return $iter;
        }
        if (is_array($iter)) {
            return new \ArrayIterator($iter);
        }

        return new \IteratorIterator($iter);
    }

    private function recordFailover(string $op, string $from, string $to, OpenAiException $e): void
    {
        $this->recorder->record(
            type: WidgetEventRecorder::TYPE_PROVIDER_FAILOVER,
            severity: WidgetEvent::SEVERITY_WARNING,
            provider: $from,
            message: "{$op}: {$from} failed, failing over to {$to}",
            context: [
                'op' => $op,
                'from' => $from,
                'to' => $to,
                'exception' => class_basename($e),
                'error' => mb_substr($e->getMessage(), 0, 300),
            ],
        );
    }

    /**
     * Record the terminal outcome when we stop trying. Only a genuine
     * provider outage on the LAST provider is a "provider_down" error; a 4xx
     * is our own malformed request and isn't a reliability event worth
     * alerting on.
     */
    private function recordExhausted(string $op, string $provider, OpenAiException $e, bool $isLast): void
    {
        if ($e instanceof OpenAiBadRequestException || ! $isLast) {
            return;
        }

        $this->recorder->record(
            type: WidgetEventRecorder::TYPE_PROVIDER_DOWN,
            severity: WidgetEvent::SEVERITY_ERROR,
            provider: $provider,
            message: "{$op}: all providers exhausted, last was {$provider}",
            context: [
                'op' => $op,
                'provider' => $provider,
                'exception' => class_basename($e),
                'error' => mb_substr($e->getMessage(), 0, 300),
            ],
        );
    }
}
