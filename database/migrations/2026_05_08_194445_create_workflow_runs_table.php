<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One workflow_run per (conversation × workflow) execution. The
     * runtime walks the steps array linearly: when a step needs visitor
     * input (a `question`), the run is paused at that step and the
     * visitor's next message satisfies it; when no input is needed, the
     * run advances synchronously inside the same SSE turn.
     *
     * vars stores collected `question` answers keyed by the question's
     * step.var_name, plus runtime-emitted variables (last_visitor_msg).
     */
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->uuid('conversation_id');
            $table->uuid('workspace_id');
            $table->string('status', 16)->default('running');
            $table->integer('current_step_index')->default(0);
            $table->json('vars')->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['conversation_id', 'status']);
            $table->index(['workflow_id', 'status']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
