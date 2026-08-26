<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            // Shared secret between the Laravel app and the deployed
            // Cloudflare Worker. Generated automatically when the admin
            // first deploys the worker.
            $table->text('internal_queue_token')->nullable();

            // Auto-deploy state for the Cloudflare cron Worker.
            $table->string('cron_worker_name')->nullable();
            $table->timestampTz('cron_worker_deployed_at')->nullable();
            $table->timestampTz('cron_worker_last_status_at')->nullable();
            $table->json('cron_worker_last_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'internal_queue_token',
                'cron_worker_name',
                'cron_worker_deployed_at',
                'cron_worker_last_status_at',
                'cron_worker_last_status',
            ]);
        });
    }
};
