<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-row agent attribution to job_runs so the queue-health widget
 * can show which agent's pipeline owns each RUNNING / DONE / FAILED
 * row. Resolved at JobProcessing time by RecordJobRun via AgentIdExtractor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_runs', function (Blueprint $table) {
            // UUIDv7 strings. Nullable because not every job is
            // agent-scoped (cron heartbeat, workspace-level bookkeeping).
            $table->string('agent_id', 64)->nullable()->after('queue');
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_runs', function (Blueprint $table) {
            $table->dropIndex(['agent_id']);
            $table->dropColumn('agent_id');
        });
    }
};
