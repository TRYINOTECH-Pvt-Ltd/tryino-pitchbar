<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI rate-limit + token-cap dials per plan. Both default null:
     *
     *   monthly_messages         — per-message quota (counted via the
     *                               'message' UsageEvent kind, separate
     *                               from monthly_conversations which
     *                               counts NEW conversation starts).
     *                               null = no per-message cap.
     *
     *   max_tokens_per_response  — clamps the LLM's max_tokens at
     *                               generation time. Frees the platform
     *                               admin to throttle the chatty open
     *                               models for the free tier without
     *                               having to maintain a code-side
     *                               default. null = use the request-level
     *                               default (currently 800).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->integer('monthly_messages')->nullable()->after('monthly_conversations');
            $table->integer('max_tokens_per_response')->nullable()->after('monthly_messages');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['monthly_messages', 'max_tokens_per_response']);
        });
    }
};
