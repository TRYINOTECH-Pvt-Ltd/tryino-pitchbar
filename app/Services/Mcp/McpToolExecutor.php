<?php

namespace App\Services\Mcp;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\McpServer;
use App\Models\McpTool;
use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Exceptions\McpException;
use App\Services\Mcp\Exceptions\McpProtocolException;
use App\Services\Mcp\Exceptions\McpTimeoutException;
use App\Services\Mcp\Exceptions\McpToolErrorException;
use App\Services\Mcp\Exceptions\McpTransportException;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use App\Services\Mcp\Support\ArgsSanitizer;
use App\Services\Mcp\Support\McpCircuitBreaker;
use App\Services\Mcp\Support\ToolOutputTruncator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Runtime heart of the MCP integration. Takes one LLM-issued tool
 * call, returns the wrapped tool result text the LLM will see on
 * the next hop.
 *
 * Guarantees:
 *  - Grant check enforced server-side. LLM hallucination of an
 *    enabled tool name fails closed.
 *  - Per-workspace rate limit prevents one runaway agent from
 *    burning the buyer's MCP server budget.
 *  - Per-server circuit breaker pauses calls after sustained
 *    failures so the conversation degrades gracefully.
 *  - Output token budget enforced; oversized responses truncated
 *    with a marker the LLM can read.
 *  - Prompt-injection defence: every tool output wrapped in
 *    <tool-result trusted="false"> tags; the system prompt (built
 *    elsewhere) instructs the model to treat as data only.
 *  - Audit log row written for every call.
 *  - Graceful degradation: failure modes return a structured error
 *    message to the LLM rather than throwing into the SSE stream.
 *    The visitor never sees a stack trace.
 */
class McpToolExecutor
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_TRANSPORT = 'transport_error';

    public const STATUS_TOOL_ERROR = 'tool_error';

    public const STATUS_SCHEMA_INVALID = 'schema_invalid';

    public const STATUS_UNAUTHORIZED = 'unauthorized';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_CIRCUIT_OPEN = 'circuit_open';

    public const STATUS_DENIED_UNKNOWN_TOOL = 'denied_unknown_tool';

    public const STATUS_DENIED_NOT_GRANTED = 'denied_not_granted';

    public function __construct(
        private readonly McpServerRegistry $registry,
        private readonly McpClient $client,
        private readonly McpCircuitBreaker $breaker,
    ) {}

    /**
     * @param  array<string, mixed>  $rawArgs
     * @return array{wrapped: string, status: string, log_id: ?int}
     */
    public function execute(
        Agent $agent,
        ?Conversation $conversation,
        string $namespacedName,
        array $rawArgs,
        int $hopBudgetMs = 5000,
    ): array {
        $requestId = (string) Str::ulid();
        $args = ArgsSanitizer::sanitize($rawArgs);

        // 1. Resolve grant — must be enabled for THIS agent.
        $tool = $this->registry->grantedToolsForAgent($agent)
            ->firstWhere('namespaced_name', $namespacedName);
        if ($tool === null) {
            return $this->fail(
                agent: $agent,
                conversation: $conversation,
                server: null,
                tool: null,
                namespacedName: $namespacedName,
                requestId: $requestId,
                args: $args,
                status: self::STATUS_DENIED_NOT_GRANTED,
                summary: "Tool [{$namespacedName}] is not enabled for this agent.",
                latencyMs: 0,
            );
        }

        $server = McpServer::query()->withoutWorkspaceScope()->find($tool->mcp_server_id);
        if ($server === null) {
            return $this->fail(
                agent: $agent,
                conversation: $conversation,
                server: null,
                tool: $tool,
                namespacedName: $namespacedName,
                requestId: $requestId,
                args: $args,
                status: self::STATUS_DENIED_UNKNOWN_TOOL,
                summary: "Tool [{$namespacedName}] has no live server.",
                latencyMs: 0,
            );
        }

        // 2. Workspace rate limit. 60 calls / minute by default.
        $rateLimitKey = "mcp:ws:{$agent->workspace_id}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 60)) {
            return $this->fail(
                agent: $agent,
                conversation: $conversation,
                server: $server,
                tool: $tool,
                namespacedName: $namespacedName,
                requestId: $requestId,
                args: $args,
                status: self::STATUS_RATE_LIMITED,
                summary: 'Workspace MCP rate limit reached.',
                latencyMs: 0,
            );
        }
        RateLimiter::hit($rateLimitKey, 60);

        // 3. Circuit breaker.
        if (! $this->breaker->shouldCall($server)) {
            return $this->fail(
                agent: $agent,
                conversation: $conversation,
                server: $server,
                tool: $tool,
                namespacedName: $namespacedName,
                requestId: $requestId,
                args: $args,
                status: self::STATUS_CIRCUIT_OPEN,
                summary: "Server [{$server->label}] is temporarily unavailable.",
                latencyMs: 0,
            );
        }

        // 4. Resolve connection (handles credentials, OAuth refresh).
        try {
            $conn = $this->registry->connectionFor($server);
        } catch (McpUnauthorizedException $e) {
            return $this->fail(
                agent: $agent,
                conversation: $conversation,
                server: $server,
                tool: $tool,
                namespacedName: $namespacedName,
                requestId: $requestId,
                args: $args,
                status: self::STATUS_UNAUTHORIZED,
                summary: $e->getMessage(),
                latencyMs: 0,
            );
        }

        // 5. Execute the call.
        $start = hrtime(true);
        $tokenBudget = (int) ($tool->output_token_budget ?? config('mcp.output_token_budget', 1200));

        try {
            $result = $this->client->callTool($conn, $tool->name, $args, $hopBudgetMs);
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
        } catch (McpTimeoutException $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->breaker->recordFailure($server);

            return $this->fail($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args,
                self::STATUS_TIMEOUT, $e->getMessage(), $latency);
        } catch (McpUnauthorizedException $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
            $server->forceFill(['status' => McpServer::STATUS_PENDING_AUTH])->save();
            $this->registry->invalidate($agent);

            return $this->fail($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args,
                self::STATUS_UNAUTHORIZED, $e->getMessage(), $latency);
        } catch (McpToolErrorException $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            // Tool error — server is healthy. Don't bump the breaker.
            // Pass the error text through so the LLM can self-correct.
            return $this->finish(
                agent: $agent,
                conversation: $conversation,
                server: $server,
                tool: $tool,
                namespacedName: $namespacedName,
                requestId: $requestId,
                args: $args,
                wrapped: $this->wrap($namespacedName, "Tool reported error: {$e->getMessage()}", $server->label, true),
                status: self::STATUS_TOOL_ERROR,
                truncated: false,
                latencyMs: $latency,
                outputTokens: null,
                outputPreview: $e->getMessage(),
            );
        } catch (McpProtocolException $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->breaker->recordFailure($server);

            return $this->fail($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args,
                self::STATUS_TRANSPORT, $e->getMessage(), $latency);
        } catch (McpTransportException $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->breaker->recordFailure($server);

            return $this->fail($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args,
                self::STATUS_TRANSPORT, $e->getMessage(), $latency);
        } catch (McpException $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->breaker->recordFailure($server);

            return $this->fail($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args,
                self::STATUS_TRANSPORT, $e->getMessage(), $latency);
        } catch (\Throwable $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);
            Log::warning('mcp.executor.unhandled', [
                'tool' => $namespacedName,
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            $this->breaker->recordFailure($server);

            return $this->fail($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args,
                self::STATUS_TRANSPORT, 'Unexpected error.', $latency);
        }

        $this->breaker->recordSuccess($server);
        $this->touchLastUsed($server);

        // 6. Truncate output to token budget.
        $truncated = ToolOutputTruncator::truncate($result->textContent, $tokenBudget);
        $wrapped = $this->wrap($namespacedName, $truncated['text'], $server->label, $result->isError);

        return $this->finish(
            agent: $agent,
            conversation: $conversation,
            server: $server,
            tool: $tool,
            namespacedName: $namespacedName,
            requestId: $requestId,
            args: $args,
            wrapped: $wrapped,
            status: $result->isError ? self::STATUS_TOOL_ERROR : self::STATUS_SUCCESS,
            truncated: $truncated['truncated'],
            latencyMs: $latency,
            outputTokens: $truncated['retained_tokens'],
            outputPreview: mb_substr($result->textContent, 0, 1024),
        );
    }

    /**
     * Wrap a tool result for re-feed to the LLM. The XML-tag pattern
     * mirrors how we wrap RAG `<source>` chunks; the system prompt
     * (PromptBuilder) tells the model to treat <tool-result> content
     * as untrusted data.
     */
    private function wrap(string $namespacedName, string $body, string $serverLabel, bool $isError): string
    {
        $errAttr = $isError ? ' error="true"' : '';

        return "<tool-result name=\"{$namespacedName}\" server=\"{$serverLabel}\" trusted=\"false\"{$errAttr}>\n"
            .$body
            ."\n</tool-result>";
    }

    /**
     * Write a structured audit row for every call.
     *
     * @param  array<string, mixed>  $args
     */
    private function log(
        Agent $agent,
        ?Conversation $conversation,
        ?McpServer $server,
        ?McpTool $tool,
        string $namespacedName,
        string $requestId,
        array $args,
        string $status,
        int $latencyMs,
        ?int $outputTokens = null,
        bool $outputTruncated = false,
        ?string $outputPreview = null,
        ?string $errorSummary = null,
    ): ?int {
        if ($server === null) {
            return null;
        }

        return (int) DB::table('mcp_call_logs')->insertGetId([
            'workspace_id' => $agent->workspace_id,
            'agent_id' => $agent->id,
            'conversation_id' => $conversation?->id,
            'mcp_server_id' => $server->id,
            'mcp_tool_id' => $tool?->id,
            'tool_name' => mb_substr($namespacedName, 0, 160),
            'request_id' => $requestId,
            'args_redacted_preview' => json_encode($this->redactArgs($args)) ?: '{}',
            'status' => $status,
            'http_status' => null,
            'latency_ms' => $latencyMs,
            'output_token_estimate' => $outputTokens,
            'output_truncated' => $outputTruncated,
            'output_preview' => $outputPreview !== null ? mb_substr($outputPreview, 0, 1024) : null,
            'error_summary' => $errorSummary !== null ? mb_substr($errorSummary, 0, 500) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{wrapped: string, status: string, log_id: ?int}
     */
    private function finish(
        Agent $agent,
        ?Conversation $conversation,
        McpServer $server,
        McpTool $tool,
        string $namespacedName,
        string $requestId,
        array $args,
        string $wrapped,
        string $status,
        bool $truncated,
        int $latencyMs,
        ?int $outputTokens,
        ?string $outputPreview,
    ): array {
        $logId = $this->log($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args, $status,
            $latencyMs, $outputTokens, $truncated, $outputPreview);

        return ['wrapped' => $wrapped, 'status' => $status, 'log_id' => $logId];
    }

    /**
     * @return array{wrapped: string, status: string, log_id: ?int}
     */
    private function fail(
        Agent $agent,
        ?Conversation $conversation,
        ?McpServer $server,
        ?McpTool $tool,
        string $namespacedName,
        string $requestId,
        array $args,
        string $status,
        string $summary,
        int $latencyMs,
    ): array {
        $wrapped = $this->wrap($namespacedName, "External integration unavailable: {$summary}", $server?->label ?? '', true);
        $logId = $this->log($agent, $conversation, $server, $tool, $namespacedName, $requestId, $args, $status,
            $latencyMs, null, false, null, $summary);

        return ['wrapped' => $wrapped, 'status' => $status, 'log_id' => $logId];
    }

    private function touchLastUsed(McpServer $server): void
    {
        DB::table('mcp_servers')->where('id', $server->id)->update(['last_used_at' => now()]);
    }

    /**
     * Redact obvious PII shapes (credit-card runs, long bare digits)
     * from args before storing the audit preview.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function redactArgs(array $args): array
    {
        return array_map(function ($v) {
            if (is_string($v)) {
                $v = preg_replace('/\b\d{13,19}\b/', '[redacted-number]', $v);
                if (mb_strlen($v) > 256) {
                    $v = mb_substr($v, 0, 256).'…[truncated]';
                }
            }

            return $v;
        }, $args);
    }
}
