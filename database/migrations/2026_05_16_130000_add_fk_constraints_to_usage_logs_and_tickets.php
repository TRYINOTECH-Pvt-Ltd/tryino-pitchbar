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
 *
 * usage_logs / tickets store UUID FKs as char(36). Postgres stores
 * conversations.id as uuid, so comparisons need id::text and the
 * columns are altered to uuid before the FK is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->nullOrphans('usage_logs', 'conversation_id', 'conversations');
        $this->nullOrphans('usage_logs', 'agent_id', 'agents');
        $this->nullOrphans('usage_logs', 'message_id', 'messages');
        $this->deleteOrphans('usage_logs', 'workspace_id', 'workspaces');

        $this->deleteOrphans('tickets', 'workspace_id', 'workspaces');
        $this->nullOrphans('tickets', 'agent_id', 'agents');
        $this->nullOrphans('tickets', 'conversation_id', 'conversations');
        $this->nullOrphans('tickets', 'assigned_to_user_id', 'users');

        if (DB::getDriverName() === 'pgsql') {
            $this->charToUuid('usage_logs', ['workspace_id', 'agent_id', 'conversation_id', 'message_id']);
            $this->charToUuid('tickets', ['workspace_id', 'agent_id', 'conversation_id']);
        }

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

    private function nullOrphans(string $table, string $column, string $parent): void
    {
        DB::statement($this->orphanPredicate(
            "UPDATE {$table} SET {$column} = NULL WHERE {$column} IS NOT NULL AND {column} NOT IN {ids}",
            $column,
            $parent,
        ));
    }

    private function deleteOrphans(string $table, string $column, string $parent): void
    {
        DB::statement($this->orphanPredicate(
            "DELETE FROM {$table} WHERE {column} NOT IN {ids}",
            $column,
            $parent,
        ));
    }

    private function orphanPredicate(string $sql, string $column, string $parent): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return strtr($sql, [
                '{column}' => "{$column}::text",
                '{ids}' => "(SELECT id::text FROM {$parent})",
            ]);
        }

        return strtr($sql, [
            '{column}' => $column,
            '{ids}' => "(SELECT id FROM {$parent})",
        ]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function charToUuid(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE uuid USING NULLIF(TRIM({$column}::text), '')::uuid");
        }
    }
};
