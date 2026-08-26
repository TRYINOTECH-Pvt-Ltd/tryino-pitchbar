<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-tick log so the admin can see in their own dashboard that
        // the Cloudflare Worker is alive and actually processing jobs.
        // Auto-pruned to the most recent 200 rows by the controller so
        // the table never grows beyond a few KB.
        Schema::create('cron_tick_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('received_at')->index();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed_in_tick')->default(0);
            $table->unsignedInteger('remaining_pending')->default(0);
            $table->unsignedInteger('failed_total')->default(0);
            $table->unsignedInteger('elapsed_ms')->default(0);
            $table->string('source')->nullable(); // 'cron' | 'manual'
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_tick_logs');
    }
};
