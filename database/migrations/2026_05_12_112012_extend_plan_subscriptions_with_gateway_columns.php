<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promote `plan_subscriptions` to the unified per-gateway ledger.
 *
 * The Workspace model carries `payment_gateway`, `stripe_id` (Cashier),
 * `paypal_subscription_id`, and `razorpay_subscription_id` — fine for
 * the live state but inconvenient for analytics, audit, multi-history,
 * and the "did this subscription churn?" view. PlanSubscription now
 * holds one row per gateway-subscription with `gateway` discriminator
 * + `gateway_subscription_id`, written by every webhook on plan
 * grant/revoke.
 *
 * Backwards-compatible: the legacy `stripe_subscription_id` column
 * stays in place and is mirrored into `gateway_subscription_id` when
 * `gateway = 'stripe'`. Existing rows keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table->string('gateway', 16)->nullable()->after('plan_id');
            $table->string('gateway_subscription_id', 191)->nullable()->after('gateway');

            $table->index(['workspace_id', 'gateway']);
            $table->unique(['gateway', 'gateway_subscription_id'], 'plan_subscriptions_gateway_sub_unique');
        });
    }

    public function down(): void
    {
        Schema::table('plan_subscriptions', function (Blueprint $table) {
            $table->dropUnique('plan_subscriptions_gateway_sub_unique');
            $table->dropIndex(['workspace_id', 'gateway']);
            $table->dropColumn(['gateway', 'gateway_subscription_id']);
        });
    }
};
