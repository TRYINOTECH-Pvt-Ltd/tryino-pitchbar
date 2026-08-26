<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-job lifecycle log. The platform-admin "Queue health" widget
     * needs to show RUNNING / DONE / FAILED rows like a tail -f, not
     * just aggregate tick counts from `cron_tick_logs`.
     *
     * Why a new table?
     *   - Laravel's `database` queue driver only keeps rows in `jobs`
     *     while pending. Once a job succeeds the row is deleted, so
     *     there's no native record of successful runs.
     *   - `failed_jobs` only records failures.
     *   - Horizon would solve this but requires Redis (and the buyer
     *     install runs on a database queue driver).
     *
     * A listener subscribed to Laravel's queue events
     * (JobProcessing / JobProcessed / JobFailed) writes here.
     * Trimmed to ~7 days by a daily cleanup, so the table never grows
     * past a few thousand rows on a typical install.
     */
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Laravel's per-job uuid; lets us correlate to failed_jobs
            // and dedupe Processing/Processed events that fire on the
            // same row (retries).
            $table->string('uuid', 64)->unique();
            $table->string('job_class', 191);
            $table->string('queue', 64)->default('default');
            // running | done | failed
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('exception_first_line')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);

            // Hot path for the widget query: ORDER BY started_at DESC LIMIT 25.
            $table->index(['started_at']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
