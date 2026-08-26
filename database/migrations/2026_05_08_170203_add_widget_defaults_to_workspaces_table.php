<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace-level widget defaults. New agents created inside the
 * workspace inherit these as their starting theme / persona / starter
 * prompts / max_chars; existing agents keep their per-agent overrides
 * unless the admin explicitly clicks "Apply to all agents" on the
 * Settings → Widget page.
 *
 * Stored as JSON because the shape mirrors agent.theme + agent.persona
 * + agent.guardrails + agent.starter_prompts and we want to keep all
 * four parts together so a single read on the workspace gives the
 * resolver everything it needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('widget_defaults')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('widget_defaults');
        });
    }
};
