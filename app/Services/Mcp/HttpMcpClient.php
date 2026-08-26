<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Contracts\McpClient;
use App\Services\Mcp\Dto\McpConnection;
use App\Services\Mcp\Dto\McpServerInfo;
use App\Services\Mcp\Dto\McpToolResult;
use App\Services\Mcp\Dto\McpToolSchema;
use App\Services\Mcp\Exceptions\McpProtocolException;
use App\Services\Mcp\Exceptions\McpTimeoutException;
use App\Services\Mcp\Exceptions\McpTransportException;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use App\Services\Mcp\Support\JsonRpcEnvelope;
use App\Support\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyGuard;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Real MCP client over Guzzle. Implements MCP Streamable HTTP
 * transport (spec 2025-03-26):
 *  - Every method is a single HTTP POST to the server URL with a
 *    JSON-RPC envelope as the body.
 *  - The server may respond with either application/json (single
 *    JSON-RPC response) or text/event-stream (one or more SSE
 *    messages, each a JSON-RPC envelope). We support both forms.
 *  - We do NOT keep long-lived SSE sessions in v1. Octane workers
 *    are recycled; pinning a worker to one MCP session across
 *    visitor turns is incompatible with the request-per-worker
 *    model. Each call is fresh POST.
 *
 * Octane safety:
 *  - Stateless wrt requests. The Guzzle client + UrlSafetyGuard +
 *    Logger are injected once; the McpConnection DTO is passed into
 *    every method and never stored on $this.
 *  - Bound as `scoped` in AppServiceProvider so a misconfigured
 *    Guzzle (proxy / timeout) gets a fresh instance per request.
 *
 * SSRF defence: UrlSafetyGuard::assertSafe runs on the connection
 * URL once per call with DNS rebind protection enabled. The cost
 * (5-50ms for one DNS lookup) is acceptable for a non-hot-path
 * call.
 *
 * Body cap: response bodies are read up to 1MB then truncated. The
 * cap is enforced via Guzzle's `stream` option + manual byte counting.
 */
class HttpMcpClient implements McpClient
{
    /**
     * Max response body we will read from an MCP server. Anything
     * past this is a misbehaving server; we bail with
     * McpProtocolException("response_too_large"). Token-level
     * truncation for legitimate-but-chunky responses happens later
     * in the executor.
     */
    private const MAX_RESPONSE_BYTES = 1_048_576; // 1 MB

    /**
     * Min connect-portion of any timeout. Even on a fast network,
     * TLS handshake to a brand-new host can eat 800ms. We cap the
     * connect at 2s and give the rest to read.
     */
    private const CONNECT_TIMEOUT_MS_CAP = 2000;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly UrlSafetyGuard $urlGuard,
        private readonly LoggerInterface $logger,
    ) {}

    public function initialize(McpConnection $conn): McpServerInfo
    {
        $result = $this->call($conn, 'initialize', [
            'protocolVersion' => $conn->protocolVersion,
            'clientInfo' => [
                'name' => $conn->clientName,
                'version' => $conn->clientVersion,
            ],
            'capabilities' => new \stdClass,
        ], 5000);

        $serverInfo = $result['serverInfo'] ?? [];
        if (! is_array($serverInfo)) {
            throw new McpProtocolException('initialize response missing serverInfo.');
        }

        return new McpServerInfo(
            protocolVersion: (string) ($result['protocolVersion'] ?? $conn->protocolVersion),
            serverName: (string) ($serverInfo['name'] ?? 'unknown'),
            serverVersion: (string) ($serverInfo['version'] ?? '0.0.0'),
            capabilities: is_array($result['capabilities'] ?? null) ? $result['capabilities'] : [],
        );
    }

    public function ping(McpConnection $conn): bool
    {
        // MCP defines `ping` as a notification with empty params;
        // server responds with `{result: {}}`. Use a short timeout
        // so the admin "Test connection" button feels snappy.
        $this->call($conn, 'ping', new \stdClass, 3000);

        return true;
    }

    public function listTools(McpConnection $conn): array
    {
        $result = $this->call($conn, 'tools/list', new \stdClass, 10000);
        $tools = $result['tools'] ?? null;
        if (! is_array($tools)) {
            throw new McpProtocolException('tools/list response missing tools array.');
        }

        $schemas = [];
        foreach ($tools as $tool) {
            if (! is_array($tool) || ! isset($tool['name'])) {
                continue;
            }

            $schemas[] = new McpToolSchema(
                name: (string) $tool['name'],
                description: isset($tool['description']) ? (string) $tool['description'] : null,
                inputSchema: is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [],
                annotations: is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [],
            );
        }

        return $schemas;
    }

    public function callTool(McpConnection $conn, string $toolName, array $args, int $timeoutMs): McpToolResult
    {
        $result = $this->call($conn, 'tools/call', [
            'name' => $toolName,
            'arguments' => empty($args) ? new \stdClass : $args,
        ], $timeoutMs);

        $blocks = is_array($result['content'] ?? null) ? $result['content'] : [];
        $isError = (bool) ($result['isError'] ?? false);

        return new McpToolResult(
            textContent: $this->flattenBlocks($blocks),
            isError: $isError,
            rawContentBlocks: $blocks,
        );
    }

    /**
     * Core JSON-RPC POST. Single helper used by every public method
     * so the SSRF check, timeout split, and exception mapping live
     * in one place.
     *
     * @param  array<string, mixed>|\stdClass  $params
     * @return array<string, mixed>
     */
    private function call(McpConnection $conn, string $method, array|\stdClass $params, int $timeoutMs): array
    {
        try {
            $this->urlGuard->assertSafe($conn->serverUrl, resolveHostnames: true);
        } catch (UnsafeUrlException $e) {
            throw new McpTransportException("Refusing to call unsafe URL: {$e->getMessage()}");
        }

        $envelope = JsonRpcEnvelope::buildRequest(
            method: $method,
            params: $params instanceof \stdClass ? [] : $params,
        );
        if ($params instanceof \stdClass) {
            $envelope['params'] = new \stdClass;
        }

        $connectTimeoutMs = min(self::CONNECT_TIMEOUT_MS_CAP, max(500, intdiv($timeoutMs, 4)));
        $readTimeoutMs = max(500, $timeoutMs - $connectTimeoutMs);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'User-Agent' => 'Pitchbar-MCP/1.0',
        ];
        if ($conn->authHeader !== null && $conn->authHeader !== '') {
            $headers['Authorization'] = $conn->authHeader;
        }

        try {
            $response = $this->http->request('POST', $conn->serverUrl, [
                RequestOptions::HEADERS => $headers,
                RequestOptions::JSON => $envelope,
                RequestOptions::CONNECT_TIMEOUT => $connectTimeoutMs / 1000,
                RequestOptions::TIMEOUT => ($connectTimeoutMs + $readTimeoutMs) / 1000,
                RequestOptions::READ_TIMEOUT => $readTimeoutMs / 1000,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::STREAM => true,
            ]);
        } catch (ConnectException $e) {
            throw new McpTransportException("Transport connect failure: {$e->getMessage()}");
        } catch (RequestException $e) {
            if ($this->isTimeout($e)) {
                throw new McpTimeoutException("Request timed out after {$timeoutMs}ms");
            }
            throw new McpTransportException("Transport request failure: {$e->getMessage()}");
        } catch (GuzzleException $e) {
            throw new McpTransportException("Transport failure: {$e->getMessage()}");
        }

        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw new McpUnauthorizedException("Server returned HTTP {$status}");
        }

        if ($status === 429) {
            $retryAfter = (int) ($response->getHeader('Retry-After')[0] ?? 0);
            $ex = new McpTransportException('Server rate-limited (429)');
            $ex->retryAfterSeconds = $retryAfter > 0 ? $retryAfter : null;
            throw $ex;
        }

        if ($status >= 500) {
            throw new McpTransportException("Server returned HTTP {$status}");
        }

        if ($status >= 400) {
            throw new McpProtocolException("Server returned HTTP {$status}");
        }

        $body = $this->readCappedBody($response->getBody());

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'text/event-stream')) {
            $decoded = $this->decodeSseBody($body);
        } else {
            $decoded = $this->decodeJsonBody($body);
        }

        return JsonRpcEnvelope::extractResult($decoded, (string) $envelope['id']);
    }

    /**
     * Read up to MAX_RESPONSE_BYTES bytes from the stream; throw if
     * the server keeps writing past the cap. Streaming + manual byte
     * count rather than letting Guzzle buffer the whole body.
     */
    private function readCappedBody(StreamInterface $body): string
    {
        $buffer = '';
        $remaining = self::MAX_RESPONSE_BYTES;
        while (! $body->eof() && $remaining > 0) {
            $chunk = $body->read(min(8192, $remaining));
            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        if (! $body->eof()) {
            throw new McpProtocolException('response_too_large');
        }

        return $buffer;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpProtocolException("Server returned malformed JSON: {$e->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new McpProtocolException('Server returned non-object JSON body.');
        }

        return $decoded;
    }

    /**
     * Parse an SSE stream containing at least one JSON-RPC envelope.
     * For Streamable HTTP, the server may emit multiple "message"
     * events; we use the LAST one whose data parses as JSON-RPC
     * (the response is the trailing event after any progress
     * notifications).
     *
     * @return array<string, mixed>
     */
    private function decodeSseBody(string $body): array
    {
        $events = preg_split("/\r?\n\r?\n/", $body) ?: [];
        $lastValid = null;
        foreach ($events as $event) {
            $dataLines = [];
            foreach (preg_split("/\r?\n/", trim($event)) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }
            if ($dataLines === []) {
                continue;
            }
            $payload = implode("\n", $dataLines);
            try {
                $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($decoded) && isset($decoded['jsonrpc'])) {
                $lastValid = $decoded;
            }
        }

        if ($lastValid === null) {
            throw new McpProtocolException('SSE stream contained no JSON-RPC envelope.');
        }

        return $lastValid;
    }

    /**
     * Flatten MCP content blocks into a single text string. Only
     * `text` blocks are emitted directly; everything else gets a
     * textual placeholder so the LLM can still reference it.
     *
     * @param  array<int, mixed>  $blocks
     */
    private function flattenBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            } elseif ($type === 'image') {
                $parts[] = '[image content — not rendered]';
            } elseif ($type === 'resource') {
                $uri = (string) ($block['resource']['uri'] ?? '');
                $parts[] = "[resource: {$uri}]";
            } else {
                $parts[] = "[content block: {$type}]";
            }
        }

        return implode("\n\n", array_filter($parts, fn ($s) => $s !== ''));
    }

    private function isTimeout(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'time-out');
    }
}
