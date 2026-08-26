<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent whitelist for MCP tools. The discovery job creates one
 * row per (agent, tool) with `enabled = false` when a tool is first
 * discovered or a new tool is added; admin must explicitly toggle a
 * tool ON before the LLM is allowed to see it.
 *
 * This is the security-critical gate: a visitor-facing chatbot must
 * NOT inherit destructive tools (refund a customer, delete a record)
 * just because an admin connected an MCP server. Admin acks each
 * tool individually for each agent.
 *
 * `enabled = false` rows STILL exist (so the admin UI can show
 * "discovered but disabled" tools) — we don't delete them when toggled
 * off. Removing the row would lose the audit trail of who enabled/
 * disabled and when.
 *
 * `config_overrides` lets the admin override per-tool execution
 * parameters (e.g. `output_token_budget` shrunk for a chatty tool,
 * or `alias` to rename the LLM-facing function name).
 *
 * Cascade: agent_id and mcp_tool_id both cascade — delete an agent
 * and its grants vanish; delete a tool (via server cascade or
 * server tombstone) and its grants vanish too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_mcp_tool_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignUuid('mcp_tool_id')->constrained('mcp_tools')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->boolean('enabled')->default(false);
            $table->timestampTz('enabled_at')->nullable();
            $table->foreignId('enabled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->json('config_overrides')->nullable();

            $table->timestampsTz();

            $table->unique(['agent_id', 'mcp_tool_id'], 'agent_mcp_tool_grants_agent_tool_unique');
            $table->index(['workspace_id', 'enabled'], 'agent_mcp_tool_grants_workspace_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mcp_tool_grants');
    }
};
