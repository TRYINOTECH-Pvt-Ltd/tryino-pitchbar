<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide widget defaults set by super_admins from
 * Settings → System → Widget defaults. New WORKSPACES inherit these on
 * creation; workspace owners can override via Settings → Widget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->json('widget_defaults')->nullable()->after('site_title');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('widget_defaults');
        });
    }
};
