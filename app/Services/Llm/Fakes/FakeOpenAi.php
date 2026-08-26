<?php

namespace App\Services\Llm\Fakes;

use App\Services\Llm\Contracts\OpenAiClient;

class FakeOpenAi implements OpenAiClient
{
    /** @var array<int, array{messages: array, opts: array}> */
    public array $chatCalls = [];

    /** @var array<int, array<int, string>> */
    public array $embedCalls = [];

    private string $defaultResponse = 'This is a stubbed response from FakeOpenAi.';

    private int $firstByteDelayMs = 0;

    private ?\Throwable $streamFailure = null;

    private int $perTokenDelayMs = 0;

    /** @var array<int, string> */
    private array $scriptedResponses = [];

    /** @var array<int, array{tool_calls?: array, content?: string}> */
    private array $scriptedToolResponses = [];

    /** @var array<int, array{messages: array, tools: array, opts: array}> */
    public array $toolCalls = [];

    public function setDefaultResponse(string $text): void
    {
        $this->defaultResponse = $text;
    }

    public function pushResponse(string $text): void
    {
        $this->scriptedResponses[] = $text;
    }

    /**
     * Queue a tool-call response. Multiple calls let tests script
     * multi-hop tool sequences (call A → result → call B → result →
     * final content).
     *
     * @param  array<string, mixed>  $args
     */
    public function pushToolCall(string $name, array $args, string $id = 'call_test'): void
    {
        $this->scriptedToolResponses[] = [
            'tool_calls' => [[
                'id' => $id,
                'name' => $name,
                'arguments' => json_encode($args, JSON_UNESCAPED_SLASHES) ?: '{}',
            ]],
        ];
    }

    /**
     * Queue a final content response (no tool calls). Use this to
     * terminate a scripted tool sequence.
     */
    public function pushToolFinalContent(string $text): void
    {
        $this->scriptedToolResponses[] = ['content' => $text];
    }

    public function setLatency(int $firstByteDelayMs, int $perTokenDelayMs = 0): void
    {
        $this->firstByteDelayMs = $firstByteDelayMs;
        $this->perTokenDelayMs = $perTokenDelayMs;
    }

    /**
     * Make the next streamChat() call throw — lets tests exercise the
     * provider-failure path (error SSE event, error turn traces).
     */
    public function failNextStreamWith(\Throwable $e): void
    {
        $this->streamFailure = $e;
    }

    public function streamChat(array $messages, array $opts = []): iterable
    {
        $this->chatCalls[] = ['messages' => $messages, 'opts' => $opts];

        if ($this->streamFailure !== null) {
            $failure = $this->streamFailure;
            $this->streamFailure = null;

            throw $failure;
        }

        $text = array_shift($this->scriptedResponses) ?? $this->defaultResponse;

        if ($this->firstByteDelayMs > 0) {
            usleep($this->firstByteDelayMs * 1000);
        }

        // Tokenize roughly as whitespace-separated chunks so consumers see streaming behavior.
        $tokens = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        foreach ($tokens as $token) {
            if ($this->perTokenDelayMs > 0) {
                usleep($this->perTokenDelayMs * 1000);
            }
            yield $token;
        }
    }

    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        $this->toolCalls[] = ['messages' => $messages, 'tools' => $tools, 'opts' => $opts];

        $next = array_shift($this->scriptedToolResponses);
        if ($next === null) {
            // No script left — treat as final content using the default
            // response so unscripted callers still get a sane payload.
            return ['content' => $this->defaultResponse, 'finish_reason' => 'stop'];
        }
        if (isset($next['tool_calls'])) {
            return ['tool_calls' => $next['tool_calls'], 'finish_reason' => 'tool_calls'];
        }

        return ['content' => $next['content'] ?? '', 'finish_reason' => 'stop'];
    }

    public function embed(array $inputs): array
    {
        $this->embedCalls[] = $inputs;

        // Deterministic synthetic vectors — sha256 -> 1536 floats in [-1, 1].
        return array_map(function (string $text): array {
            $hash = hash('sha256', $text, true);
            $vec = [];
            for ($i = 0; $i < 1536; $i++) {
                $byte = ord($hash[$i % 32]);
                $vec[] = ($byte / 127.5) - 1.0;
            }

            return $vec;
        }, $inputs);
    }
}
