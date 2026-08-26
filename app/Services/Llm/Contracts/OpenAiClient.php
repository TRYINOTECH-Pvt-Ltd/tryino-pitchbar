<?php

namespace App\Services\Llm\Contracts;

interface OpenAiClient
{
    /**
     * Stream a chat completion. Yields string tokens.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts
     * @return iterable<int, string>
     */
    public function streamChat(array $messages, array $opts = []): iterable;

    /**
     * Non-streaming chat completion that may return tool calls. Used
     * during tool-resolution loops before the final answer streams.
     *
     * The return shape matches the OpenAI Chat Completions response:
     *   - `tool_calls`: list of {id, name, arguments-as-JSON-string}
     *     when the model wants to invoke tools
     *   - `content`: final assistant text when no more tools needed
     *   - `finish_reason`: provider-supplied stop reason
     *
     * Implementations that don't natively support tool calling should
     * ignore the `tools` opt and return a content response — callers
     * detect the absence of tool_calls and proceed with streaming.
     *
     * @param  array<int, array{role: string, content?: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $opts
     * @return array{tool_calls?: array<int, array{id: string, name: string, arguments: string}>, content?: string, finish_reason?: string}
     */
    public function chatWithTools(array $messages, array $tools, array $opts = []): array;

    /**
     * Embed inputs. Returns one float vector per input.
     *
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array;
}
