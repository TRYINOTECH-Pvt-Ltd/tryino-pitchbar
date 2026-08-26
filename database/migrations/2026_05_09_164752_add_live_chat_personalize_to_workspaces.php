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
            // When true (default), live-chat handoff shows the operator's
            // real name + avatar in the visitor's widget ("Sarah joined
            // the chat"). When false, the visitor sees a brand-only label
            // ("An agent from <Brand>"). Used by regulated industries
            // (legal, healthcare, finance) that prefer not exposing
            // individual operator identities.
            $table->boolean('live_chat_personalize')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('live_chat_personalize');
        });
    }
};
