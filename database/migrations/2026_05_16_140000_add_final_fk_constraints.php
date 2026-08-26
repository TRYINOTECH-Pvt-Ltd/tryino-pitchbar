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
        DB::statement('UPDATE conversations SET claimed_by_user_id = NULL WHERE claimed_by_user_id IS NOT NULL AND claimed_by_user_id NOT IN (SELECT id FROM users)');
        DB::statement('UPDATE workspaces SET lifetime_plan_id = NULL WHERE lifetime_plan_id IS NOT NULL AND lifetime_plan_id NOT IN (SELECT id FROM plans)');

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
};
