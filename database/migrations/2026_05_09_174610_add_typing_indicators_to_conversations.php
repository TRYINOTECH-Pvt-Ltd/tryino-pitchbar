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
        Schema::table('conversations', function (Blueprint $table) {
            // Self-expiring typing windows. POST /typing on either side
            // sets the relevant column to now() + 5s. The poll endpoints
            // compare it to now() — if it's in the future, render the
            // "is typing…" indicator. Stale-clean themselves; no cron
            // needed.
            $table->timestamp('operator_typing_until')->nullable();
            $table->timestamp('visitor_typing_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['operator_typing_until', 'visitor_typing_until']);
        });
    }
};
