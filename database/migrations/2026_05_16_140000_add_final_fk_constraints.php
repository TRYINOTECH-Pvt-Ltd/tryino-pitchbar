<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FK constraints on conversations.claimed_by_user_id + workspaces.lifetime_plan_id.
 * Both SET NULL on parent delete (preserve child rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->nullOrphans('conversations', 'claimed_by_user_id', 'users');
        $this->nullOrphans('workspaces', 'lifetime_plan_id', 'plans');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE workspaces ALTER COLUMN lifetime_plan_id TYPE uuid USING NULLIF(TRIM(lifetime_plan_id::text), '')::uuid");
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('claimed_by_user_id', 'conversations_claimed_by_user_id_foreign')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreign('lifetime_plan_id', 'workspaces_lifetime_plan_id_foreign')
                ->references('id')->on('plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign('workspaces_lifetime_plan_id_foreign');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign('conversations_claimed_by_user_id_foreign');
        });
    }

    private function nullOrphans(string $table, string $column, string $parent): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE {$table} SET {$column} = NULL WHERE {$column} IS NOT NULL AND {$column}::text NOT IN (SELECT id::text FROM {$parent})");

            return;
        }

        DB::statement("UPDATE {$table} SET {$column} = NULL WHERE {$column} IS NOT NULL AND {$column} NOT IN (SELECT id FROM {$parent})");
    }
};
