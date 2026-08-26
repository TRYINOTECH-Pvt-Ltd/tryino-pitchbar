<?php

namespace App\Services\Llm;

use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiBadRequestException;
use App\Services\Llm\Exceptions\OpenAiException;
use App\Services\Llm\Exceptions\OpenAiRateLimitException;
use App\Services\Llm\Exceptions\OpenAiTimeoutException;
use GuzzleHttp\Client as Guzzle;
use OpenAI;
use OpenAI\Client as OpenAiSdk;
use OpenAI\Exceptions\ErrorException;

class OpenAiHttpClient implements OpenAiClient
{
    private OpenAiSdk $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gpt-4o-mini',
        private readonly string $embedModel = 'text-embedding-3-small',
        ?string $baseUri = null,
        array $extraHeaders = [],
    ) {
        $factory = OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withHttpHeader('User-Agent', 'pitchbar/1.0')
            ->withHttpClient(new Guzzle([
                // Fail fast on a dead provider so FailoverOpenAiClient can move
                // on: a 5s connect cap catches "host down" immediately; the 60s
                // overall cap matches WorkersAiClient and still lets a long
                // streamed answer finish. (The SDK shares one client across
                // streamed + blocking calls, so we can't go tighter on blocking
                // without truncating real streams.)
                'connect_timeout' => 5,
                'timeout' => 60,
            ]));

        if ($baseUri !== null && $baseUri !== '') {
            $factory = $factory->withBaseUri(rtrim($baseUri, '/'));
        }

        foreach ($extraHeaders as $name => $value) {
            $factory = $factory->withHttpHeader($name, $value);
        }

        $this->client = $factory->make();
    }

    public function streamChat(array $messages, array $opts = []): iterable
    {
        try {
            $stream = $this->client->chat()->createStreamed([
                'model' => $opts['model'] ?? $this->chatModel,
                'messages' => $messages,
                'max_tokens' => $opts['max_tokens'] ?? 800,
                'temperature' => $opts['temperature'] ?? 0.4,
            ]);

            foreach ($stream as $chunk) {
                $delta = $chunk->choices[0]->delta->content ?? '';
                if ($delta !== '') {
                    yield $delta;
                }
            }
        } catch (ErrorException $e) {
            throw match (true) {
                str_contains(strtolower($e->getMessage()), 'rate limit') => new OpenAiRateLimitException($e->getMessage(), 0, $e),
                str_contains(strtolower($e->getMessage()), 'timeout') => new OpenAiTimeoutException($e->getMessage(), 0, $e),
                default => new OpenAiBadRequestException($e->getMessage(), 0, $e),
            };
        } catch (\Throwable $e) {
            throw new OpenAiException($e->getMessage(), 0, $e);
        }
    }

    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        try {
            $payload = [
                'model' => $opts['model'] ?? $this->chatModel,
                'messages' => $messages,
                'max_tokens' => $opts['max_tokens'] ?? 800,
                'temperature' => $opts['temperature'] ?? 0.4,
            ];
            if ($tools !== []) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = $opts['tool_choice'] ?? 'auto';
            }

            $response = $this->client->chat()->create($payload);
            $choice = $response->choices[0] ?? null;
            if ($choice === null) {
                return ['content' => '', 'finish_reason' => 'stop'];
            }

            $finish = (string) ($choice->finishReason ?? 'stop');
            $msg = $choice->message ?? null;
            $rawTools = $msg?->toolCalls ?? [];

            if ($rawTools !== []) {
                $tool_calls = [];
                foreach ($rawTools as $tc) {
                    $tool_calls[] = [
                        'id' => (string) ($tc->id ?? ''),
                        'name' => (string) ($tc->function->name ?? ''),
                        'arguments' => (string) ($tc->function->arguments ?? '{}'),
                    ];
                }

                return ['tool_calls' => $tool_calls, 'finish_reason' => $finish];
            }

            return [
                'content' => (string) ($msg->content ?? ''),
                'finish_reason' => $finish,
            ];
        } catch (ErrorException $e) {
            throw match (true) {
                str_contains(strtolower($e->getMessage()), 'rate limit') => new OpenAiRateLimitException($e->getMessage(), 0, $e),
                str_contains(strtolower($e->getMessage()), 'timeout') => new OpenAiTimeoutException($e->getMessage(), 0, $e),
                default => new OpenAiBadRequestException($e->getMessage(), 0, $e),
            };
        } catch (\Throwable $e) {
            throw new OpenAiException($e->getMessage(), 0, $e);
        }
    }

    public function embed(array $inputs): array
    {
        try {
            $response = $this->client->embeddings()->create([
                'model' => $this->embedModel,
                'input' => $inputs,
            ]);

            return array_map(
                fn ($e) => $e->embedding,
                $response->embeddings,
            );
        } catch (ErrorException $e) {
            throw match (true) {
                str_contains(strtolower($e->getMessage()), 'rate limit') => new OpenAiRateLimitException($e->getMessage(), 0, $e),
                str_contains(strtolower($e->getMessage()), 'timeout') => new OpenAiTimeoutException($e->getMessage(), 0, $e),
                default => new OpenAiBadRequestException($e->getMessage(), 0, $e),
            };
        } catch (\Throwable $e) {
            throw new OpenAiException($e->getMessage(), 0, $e);
        }
    }
}
