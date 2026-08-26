<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-agent toggle that gates the chat surface behind a Name +
     * Email form. When true, the widget renders a lead form first;
     * once submitted (creating the Lead immediately), the chat panel
     * unlocks. The classic Intercom / Drift conversion pattern —
     * higher capture rate because the visitor is still motivated to
     * identify themselves before getting their answer.
     *
     * false (default) = current behaviour (chat opens immediately,
     * the inline lead form surfaces only when the LLM raises the
     * `lead_prompt` flag mid-conversation).
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('require_lead_before_chat')
                ->default(false)
                ->after('restricted_paths');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('require_lead_before_chat');
        });
    }
};
