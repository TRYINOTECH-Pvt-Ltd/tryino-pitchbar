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
            // Visitor explicitly asked for a human (clicked the
            // "Connect me with a human" pill or similar). When set
            // and `claimed_by_user_id` is null, the conversation sits
            // in the operator-side "Needs human" queue and the bot
            // serves a holding bubble instead of an LLM reply.
            $table->timestamp('human_requested_at')
                ->nullable()
                ->after('claimed_at');

            // Compound index for the "Needs human" filter:
            // WHERE human_requested_at IS NOT NULL AND claimed_by_user_id IS NULL
            $table->index(['human_requested_at', 'claimed_by_user_id'], 'conversations_needs_human_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_needs_human_idx');
            $table->dropColumn('human_requested_at');
        });
    }
};
