<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lock the shape of the four MCP tables so a future rebase or
 * accidental migration edit doesn't silently strip a column or index
 * that the runtime depends on. Each table gets one test asserting
 * presence of every column the application code reads or writes.
 *
 * We do NOT assert column TYPES here — Laravel's grammar maps types
 * differently on MySQL vs SQLite (notably enum becomes varchar+check
 * on SQLite) and a type assertion would either be wrong on one
 * driver or trivially restate Laravel's grammar. We assert presence
 * + index existence only.
 */
test('mcp_servers table has the columns the application reads', function () {
    $columns = Schema::getColumnListing('mcp_servers');

    $expected = [
        'id', 'workspace_id', 'label', 'server_url', 'server_url_hash',
        'transport', 'auth_type', 'credentials_encrypted', 'oauth_state',
        'server_info', 'status', 'connection_test_at', 'connection_test_result',
        'tools_synced_at', 'tools_sync_error', 'last_used_at', 'failure_count',
        'created_by_user_id', 'created_at', 'updated_at', 'deleted_at',
    ];
    $missing = array_values(array_diff($expected, $columns));
    expect($missing)->toBe([]);
});

test('mcp_tools table has the columns the application reads', function () {
    $columns = Schema::getColumnListing('mcp_tools');

    $expected = [
        'id', 'mcp_server_id', 'workspace_id', 'name', 'namespaced_name',
        'description', 'input_schema', 'output_token_budget', 'is_destructive',
        'is_idempotent', 'requires_open_world', 'catalogue_revision',
        'removed_at', 'created_at', 'updated_at',
    ];
    $missing = array_values(array_diff($expected, $columns));
    expect($missing)->toBe([]);
});

test('agent_mcp_tool_grants table has the columns the application reads', function () {
    $columns = Schema::getColumnListing('agent_mcp_tool_grants');

    $expected = [
        'id', 'agent_id', 'mcp_tool_id', 'workspace_id', 'enabled',
        'enabled_at', 'enabled_by_user_id', 'config_overrides',
        'created_at', 'updated_at',
    ];
    $missing = array_values(array_diff($expected, $columns));
    expect($missing)->toBe([]);
});

test('mcp_call_logs table has the columns the application reads', function () {
    $columns = Schema::getColumnListing('mcp_call_logs');

    $expected = [
        'id', 'workspace_id', 'agent_id', 'conversation_id', 'mcp_server_id',
        'mcp_tool_id', 'tool_name', 'request_id', 'args_redacted_preview',
        'status', 'http_status', 'latency_ms', 'output_token_estimate',
        'output_truncated', 'output_preview', 'error_summary', 'created_at',
    ];
    $missing = array_values(array_diff($expected, $columns));
    expect($missing)->toBe([]);
});

test('unique constraint blocks duplicate (workspace_id, server_url_hash) on mcp_servers', function () {
    $bag = workspaceMemberWithAgent();

    DB::table('mcp_servers')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $bag['workspace']->id,
        'label' => 'First',
        'server_url' => 'https://example.com/mcp',
        'server_url_hash' => str_repeat('a', 64),
        'transport' => 'http',
        'auth_type' => 'none',
        'status' => 'pending_auth',
        'failure_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('mcp_servers')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $bag['workspace']->id,
        'label' => 'Second',
        'server_url' => 'https://example.com/mcp',
        'server_url_hash' => str_repeat('a', 64),
        'transport' => 'http',
        'auth_type' => 'none',
        'status' => 'pending_auth',
        'failure_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('unique constraint blocks duplicate (mcp_server_id, name) on mcp_tools', function () {
    $bag = workspaceMemberWithAgent();

    $serverId = (string) Str::uuid7();
    DB::table('mcp_servers')->insert([
        'id' => $serverId,
        'workspace_id' => $bag['workspace']->id,
        'label' => 'S',
        'server_url' => 'https://example.com/mcp',
        'server_url_hash' => str_repeat('b', 64),
        'transport' => 'http',
        'auth_type' => 'none',
        'status' => 'active',
        'failure_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('mcp_tools')->insert([
        'id' => (string) Str::uuid7(),
        'mcp_server_id' => $serverId,
        'workspace_id' => $bag['workspace']->id,
        'name' => 'search',
        'namespaced_name' => 's.search',
        'description' => 'x',
        'input_schema' => '{}',
        'is_destructive' => false,
        'is_idempotent' => true,
        'requires_open_world' => true,
        'catalogue_revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('mcp_tools')->insert([
        'id' => (string) Str::uuid7(),
        'mcp_server_id' => $serverId,
        'workspace_id' => $bag['workspace']->id,
        'name' => 'search',
        'namespaced_name' => 's.search-2',
        'description' => 'x',
        'input_schema' => '{}',
        'is_destructive' => false,
        'is_idempotent' => true,
        'requires_open_world' => true,
        'catalogue_revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
