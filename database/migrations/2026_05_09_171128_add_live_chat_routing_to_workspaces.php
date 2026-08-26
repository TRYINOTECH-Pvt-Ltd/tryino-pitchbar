<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Slack / Microsoft Teams incoming-webhook URLs. When set,
            // LiveChatNotifier (queued listener on HumanRequestedEvent)
            // posts a compact JSON to each one so operators get pinged
            // outside the dashboard.
            $table->string('slack_webhook_url', 1024)->nullable();
            $table->string('teams_webhook_url', 1024)->nullable();

            // Per-workspace business hours config. Shape:
            //   {
            //     "enabled": bool,
            //     "timezone": "America/New_York",
            //     "schedule": {
            //       "monday":    [{"start":"09:00","end":"17:00"}],
            //       ...
            //     }
            //   }
            // BusinessHours::isOpen() reads it; null/missing = always open.
            $table->json('business_hours')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'slack_webhook_url',
                'teams_webhook_url',
                'business_hours',
            ]);
        });
    }
};
