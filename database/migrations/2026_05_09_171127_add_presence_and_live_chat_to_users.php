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
        Schema::table('users', function (Blueprint $table) {
            // Presence heartbeat. The admin tab pings POST /app/me/presence
            // every 60s while it's the active foreground tab. Indexed
            // because WorkspacePresence::activeOperators filters on
            // last_active_at >= now()->subMinutes(2).
            $table->timestamp('last_active_at')->nullable()->index();

            // Operator opt-in for live chat. Off by default — workspace
            // members aren't presumed to be on call. Toggled in Profile.
            $table->boolean('live_chat_available')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_active_at']);
            $table->dropColumn(['last_active_at', 'live_chat_available']);
        });
    }
};
