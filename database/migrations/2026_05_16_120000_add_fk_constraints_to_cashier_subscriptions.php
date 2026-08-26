<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add FK constraints to Cashier-style subscriptions + subscription_items.
 * cascadeOnDelete matches the sibling plan_subscriptions table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('workspace_id', 'subscriptions_workspace_id_foreign')
                ->references('id')->on('workspaces')
                ->cascadeOnDelete();
        });

        Schema::table('subscription_items', function (Blueprint $table) {
            $table->foreign('subscription_id', 'subscription_items_subscription_id_foreign')
                ->references('id')->on('subscriptions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            $table->dropForeign('subscription_items_subscription_id_foreign');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign('subscriptions_workspace_id_foreign');
        });
    }
};
