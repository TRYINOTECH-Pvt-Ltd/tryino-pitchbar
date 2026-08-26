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
        Schema::table('plans', function (Blueprint $table) {
            // When true, a workspace that signs up on this plan starts a
            // time-limited trial (no card) instead of an open-ended plan.
            $table->boolean('is_trial')->default(false)->after('is_default_for_signup');
            // Trial length in days (e.g. 7 / 14 / 30). NULL when not a
            // trial plan; the signup flow falls back to 14 if a plan is
            // flagged is_trial but left without an explicit length.
            $table->unsignedSmallInteger('trial_days')->nullable()->after('is_trial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['is_trial', 'trial_days']);
        });
    }
};
