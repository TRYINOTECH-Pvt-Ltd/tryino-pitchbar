<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached catalogue of tools exposed by an MCP server. Refreshed by
 * McpToolDiscovery on attach, on admin "Refresh" click, and on a
 * scheduled daily job.
 *
 * `name` is the tool name as the server reports it (e.g. "search_issues").
 * `namespaced_name` is what the LLM sees in its tools[] payload
 * (e.g. "linear.search_issues") — built as "{server_label_slug}.{name}".
 * Two servers exposing tools with the same local name (e.g. both
 * "search") would collide on a flat namespace, so we always emit
 * the namespaced form to the LLM and route back to the right server
 * by stripping the prefix.
 *
 * `catalogue_revision` is incremented on every successful refresh.
 * If the server's reported `input_schema` for a tool changes between
 * refreshes, the discovery job ALSO flips `enabled = false` on every
 * existing grant for that tool — admin must re-approve a tool whose
 * interface has drifted. This is the "schema drift mid-conversation"
 * defence; agents stop receiving the changed tool in their payload
 * until the admin acks.
 *
 * `removed_at` is a soft tombstone — when a server stops reporting
 * a tool, we don't delete the row (would break audit references) but
 * we filter it out of the agent's tool payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('namespaced_name', 160);
            $table->text('description')->nullable();
            $table->json('input_schema');

            $table->unsignedInteger('output_token_budget')->nullable();
            $table->boolean('is_destructive')->default(false);
            $table->boolean('is_idempotent')->default(true);
            $table->boolean('requires_open_world')->default(true);

            $table->unsignedBigInteger('catalogue_revision')->default(1);
            $table->timestampTz('removed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['mcp_server_id', 'name'], 'mcp_tools_server_name_unique');
            $table->index('namespaced_name', 'mcp_tools_namespaced_name_idx');
            $table->index(['workspace_id', 'removed_at'], 'mcp_tools_workspace_removed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tools');
    }
};
