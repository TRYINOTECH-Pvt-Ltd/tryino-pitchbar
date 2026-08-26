<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chatflow / workflow rows. One workflow = one trigger + a linear
     * sequence of steps the agent runs when the trigger fires. Stored
     * as JSON `definition` instead of a nodes/edges normal-form schema
     * so the admin can rearrange / edit without DB migrations and the
     * runtime can clone a definition into a workflow_run snapshot
     * without join soup.
     *
     * Phase 1 supports:
     *   trigger_kind = on_keyword
     *   step types   = message | question | escalate
     *
     * Phase 2 will introduce branch nodes + variable-based routing
     * (the `definition` shape is forwards-compatible — older runtime
     * builds will simply skip step types they don't recognise).
     */
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('agent_id')->nullable();
            $table->string('name', 120);
            $table->string('status', 16)->default('draft');
            $table->string('trigger_kind', 32)->default('on_keyword');
            $table->json('trigger_config')->nullable();
            $table->json('definition');
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'status']);
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
