<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every MCP tool call. Separate from the global
 * audit_logs table because tool calls are high-volume operational
 * telemetry (potentially per-turn) and need a different retention
 * (30 days) from operator-action history.
 *
 * `conversation_id` is unindexed FK-free string — conversations may
 * be deleted while the audit row should survive for accountant /
 * compliance purposes.
 *
 * `mcp_tool_id` is nullable because a tool may be tombstoned by a
 * later catalogue refresh; the log row needs to survive that. The
 * denormalised `tool_name` column carries the human-readable name
 * for filtering even after the tool row is removed.
 *
 * `request_id` is a ULID surfaced in the admin "Activity" view so
 * operators can correlate a Pitchbar audit row with a log entry on
 * the buyer's own MCP server side.
 *
 * `args_redacted_preview` is the first ~512 chars of the JSON args
 * with PII shapes scrubbed (credit-card pattern, SSN pattern, etc).
 * `output_preview` is the first ~1KB of the tool result. Neither
 * stores the full payload — full responses can be many MB and are
 * not Pitchbar's data to retain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_call_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->foreignUuid('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            $table->foreignUuid('mcp_tool_id')->nullable()->constrained('mcp_tools')->nullOnDelete();

            $table->string('tool_name', 160);
            $table->char('request_id', 26);

            $table->json('args_redacted_preview')->nullable();

            $table->enum('status', [
                'success',
                'timeout',
                'transport_error',
                'tool_error',
                'schema_invalid',
                'unauthorized',
                'rate_limited',
                'circuit_open',
                'denied_unknown_tool',
                'denied_not_granted',
            ]);

            $table->smallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('output_token_estimate')->nullable();
            $table->boolean('output_truncated')->default(false);
            $table->text('output_preview')->nullable();
            $table->string('error_summary', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at'], 'mcp_call_logs_workspace_created_idx');
            $table->index(['mcp_server_id', 'status', 'created_at'], 'mcp_call_logs_server_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_call_logs');
    }
};
