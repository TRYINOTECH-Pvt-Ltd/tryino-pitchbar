<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->boolean('marketing_widget_enabled')->default(false)->after('marketing_site_enabled');
            $table->uuid('marketing_widget_agent_id')->nullable()->after('marketing_widget_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['marketing_widget_enabled', 'marketing_widget_agent_id']);
        });
    }
};
