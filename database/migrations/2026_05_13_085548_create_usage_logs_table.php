<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-LLM-call usage breadcrumbs. The chat conversation table records
 * the user-facing turn; this records every upstream call we made to
 * answer that turn (chat completion + embedding + reranker + tool
 * fan-out). Persisted via a queued PersistUsageJob fired AFTER the SSE
 * stream completes — never on the hot path.
 *
 * v2.0.0 ships this silently: operator sees per-workspace token burn
 * in /admin/usage, plans still gate on conversation count. A later release will
 * flip plans to optionally gate on tokens too once the data shape
 * proves out.
 *
 * `cost_usd_micro` is dollars × 1,000,000 (so $0.000125 = 125). Wider
 * range than cents for sub-cent embedding calls, cheaper to graph than
 * a float, exact when summed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('usage_logs');
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('workspace_id', 36);
            $table->char('agent_id', 36)->nullable();
            $table->char('conversation_id', 36)->nullable();
            $table->char('message_id', 36)->nullable();
            $table->string('provider', 32);
            $table->string('model', 96);
            $table->string('purpose', 32);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_usd_micro')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'model', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
