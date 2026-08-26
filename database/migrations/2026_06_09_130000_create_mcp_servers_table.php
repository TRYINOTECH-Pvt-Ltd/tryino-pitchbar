<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP (Model Context Protocol) server connections — one row per
 * server attached to a workspace. Buyers paste a URL + credentials,
 * Pitchbar's agent calls the server's tools mid-conversation.
 *
 * Per-agent attachment lives in agent_mcp_tool_grants, not here,
 * because a server attached to multiple agents should not duplicate
 * its tool catalogue or credentials.
 *
 * Storage NOT folded into integration_connections because:
 *   - OAuth-PKCE token-refresh state (expires_at, refresh_token,
 *     code_verifier hash) needs a richer schema than the existing
 *     JSON config column.
 *   - Per-server tool catalogues, circuit-breaker failure counts,
 *     and connection-test history live alongside the server config
 *     and benefit from dedicated columns + indexes.
 *
 * Tenancy: workspace_id is the global filter. The model uses the
 * BelongsToWorkspace trait so admin UI listings respect the current
 * workspace; webhook + hot-path lookups bypass with explicit
 * withoutWorkspaceScope() comments.
 *
 * Same-URL dedupe: server_url_hash is a SHA-256 of the canonicalised
 * URL (host lowercased, default port stripped, fragment removed,
 * trailing slash trimmed). Unique on (workspace_id, server_url_hash)
 * means "re-add the same server" is detected at the DB layer and
 * surfaced as "manage existing connection" in the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('label', 120);
            $table->string('server_url', 2000);
            $table->char('server_url_hash', 64);

            $table->string('transport', 20)->default('http');
            $table->enum('auth_type', ['none', 'bearer', 'oauth2_pkce'])->default('none');
            $table->text('credentials_encrypted')->nullable();
            $table->json('oauth_state')->nullable();
            $table->json('server_info')->nullable();

            $table->enum('status', ['pending_auth', 'active', 'degraded', 'disabled', 'revoked'])
                ->default('pending_auth');

            $table->timestampTz('connection_test_at')->nullable();
            $table->json('connection_test_result')->nullable();
            $table->timestampTz('tools_synced_at')->nullable();
            $table->text('tools_sync_error')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['workspace_id', 'server_url_hash'], 'mcp_servers_workspace_url_unique');
            $table->index(['workspace_id', 'status'], 'mcp_servers_workspace_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
