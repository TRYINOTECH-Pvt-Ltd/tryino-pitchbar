<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            // Opt-in: super_admin gets a daily summary email of new users,
            // new paid subscriptions, new workspaces, and new leads
            // captured in the previous 24 hours. Disabled by default so
            // existing installs don't suddenly start emailing.
            $table->boolean('admin_daily_digest_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('admin_daily_digest_enabled');
        });
    }
};
