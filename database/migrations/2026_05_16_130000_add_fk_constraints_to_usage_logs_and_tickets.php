<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add FK constraints to usage_logs + tickets. Cascade rules:
 *   workspace_id        → CASCADE
 *   agent_id / conv_id / message_id / assigned_to_user_id → SET NULL
 *
 * Orphan rows are null-ed (or deleted, for NOT NULL workspace_id)
 * before the constraint is added so the FK creation doesn't fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE usage_logs SET conversation_id = NULL WHERE conversation_id IS NOT NULL AND conversation_id NOT IN (SELECT id FROM conversations)');
        DB::statement('UPDATE usage_logs SET agent_id = NULL WHERE agent_id IS NOT NULL AND agent_id NOT IN (SELECT id FROM agents)');
        DB::statement('UPDATE usage_logs SET message_id = NULL WHERE message_id IS NOT NULL AND message_id NOT IN (SELECT id FROM messages)');
        DB::statement('DELETE FROM usage_logs WHERE workspace_id NOT IN (SELECT id FROM workspaces)');

        DB::statement('DELETE FROM tickets WHERE workspace_id NOT IN (SELECT id FROM workspaces)');
        DB::statement('UPDATE tickets SET agent_id = NULL WHERE agent_id IS NOT NULL AND agent_id NOT IN (SELECT id FROM agents)');
        DB::statement('UPDATE tickets SET conversation_id = NULL WHERE conversation_id IS NOT NULL AND conversation_id NOT IN (SELECT id FROM conversations)');
        DB::statement('UPDATE tickets SET assigned_to_user_id = NULL WHERE assigned_to_user_id IS NOT NULL AND assigned_to_user_id NOT IN (SELECT id FROM users)');

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->foreign('workspace_id', 'usage_logs_workspace_id_foreign')
                ->references('id')->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('agent_id', 'usage_logs_agent_id_foreign')
                ->references('id')->on('agents')
                ->nullOnDelete();
            $table->foreign('conversation_id', 'usage_logs_conversation_id_foreign')
                ->references('id')->on('conversations')
                ->nullOnDelete();
            $table->foreign('message_id', 'usage_logs_message_id_foreign')
                ->references('id')->on('messages')
                ->nullOnDelete();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('workspace_id', 'tickets_workspace_id_foreign')
                ->references('id')->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('agent_id', 'tickets_agent_id_foreign')
                ->references('id')->on('agents')
                ->nullOnDelete();
            $table->foreign('conversation_id', 'tickets_conversation_id_foreign')
                ->references('id')->on('conversations')
                ->nullOnDelete();
            $table->foreign('assigned_to_user_id', 'tickets_assigned_to_user_id_foreign')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign('tickets_assigned_to_user_id_foreign');
            $table->dropForeign('tickets_conversation_id_foreign');
            $table->dropForeign('tickets_agent_id_foreign');
            $table->dropForeign('tickets_workspace_id_foreign');
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropForeign('usage_logs_message_id_foreign');
            $table->dropForeign('usage_logs_conversation_id_foreign');
            $table->dropForeign('usage_logs_agent_id_foreign');
            $table->dropForeign('usage_logs_workspace_id_foreign');
        });
    }
};
