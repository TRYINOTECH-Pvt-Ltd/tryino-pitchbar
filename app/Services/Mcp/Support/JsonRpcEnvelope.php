<?php

namespace App\Services\Mcp\Support;

use App\Services\Mcp\Exceptions\McpProtocolException;
use App\Services\Mcp\Exceptions\McpToolErrorException;
use App\Services\Mcp\Exceptions\McpUnauthorizedException;
use Illuminate\Support\Str;

/**
 * JSON-RPC 2.0 envelope helpers for the MCP client. Pulls request
 * id generation, response validation, and error-code-to-exception
 * mapping out of the transport class so the client stays small.
 *
 * Request id is a 26-char ULID so the server's logs can be
 * correlated with Pitchbar's mcp_call_logs row by request_id.
 */
final class JsonRpcEnvelope
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{jsonrpc: '2.0', id: string, method: string, params: array<string, mixed>}
     */
    public static function buildRequest(string $method, array $params, ?string $id = null): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id ?? (string) Str::ulid(),
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * Validate the JSON-RPC envelope shape and return the `result`
     * payload. Throws McpProtocolException on any structural issue,
     * McpUnauthorizedException on error code -32001 (custom MCP auth
     * code), McpToolErrorException on any other error envelope.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    public static function extractResult(array $decoded, string $expectedId): array
    {
        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            throw new McpProtocolException('Response is missing or has wrong jsonrpc field.');
        }

        $responseId = $decoded['id'] ?? null;
        if ($responseId !== null && (string) $responseId !== $expectedId) {
            throw new McpProtocolException("Response id mismatch (expected {$expectedId}).");
        }

        if (isset($decoded['error'])) {
            $error = is_array($decoded['error']) ? $decoded['error'] : [];
            $code = (int) ($error['code'] ?? 0);
            $message = (string) ($error['message'] ?? 'Unknown JSON-RPC error');

            if ($code === -32001 || $code === -32002) {
                throw new McpUnauthorizedException($message);
            }

            throw new McpToolErrorException($message);
        }

        if (! isset($decoded['result']) || ! is_array($decoded['result'])) {
            throw new McpProtocolException('Response is missing the result field.');
        }

        return $decoded['result'];
    }
}
