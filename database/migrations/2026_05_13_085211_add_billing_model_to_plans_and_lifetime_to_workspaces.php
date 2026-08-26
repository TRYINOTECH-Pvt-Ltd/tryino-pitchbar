<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifetime Deal (LTD) plan type. AppSumo-style one-time purchase that
 * unlocks a workspace forever — no renewal, no auto-bill, no Cashier
 * subscription. Quotas still enforced.
 *
 * `plans.billing_model` — one of:
 *   - subscription (default — existing rows back-fill to this)
 *   - lifetime     (one-time charge, permanent access)
 *   - one_time     (one-time charge, expires after a fixed window —
 *                   reserved for future use, identical wiring as
 *                   lifetime today)
 *
 * `workspaces.lifetime_plan_id` records the LTD plan the workspace
 * bought into. When set, plan-feature gates short-circuit subscription
 * checks and read from this column instead.
 *
 * `workspaces.lifetime_purchased_at` is informational — surfaces "owned
 * since X" on the billing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('billing_model', 24)->default('subscription')->after('interval');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->char('lifetime_plan_id', 36)->nullable();
            $table->timestamp('lifetime_purchased_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('billing_model');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['lifetime_plan_id', 'lifetime_purchased_at']);
        });
    }
};
