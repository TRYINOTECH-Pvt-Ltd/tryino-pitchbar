<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track per-workspace identifiers for the non-Stripe gateways.
 *
 * Stripe customer/sub IDs are owned by Cashier (`workspaces.stripe_id`,
 * `subscriptions` table). PayPal and Razorpay don't ship with Cashier-
 * style scaffolding, so we persist the active subscription id directly
 * on the workspace row — same shape the existing Stripe webhook uses to
 * map customer → workspace.
 *
 * `payment_gateway` records which provider currently owns the active
 * paid subscription. Null = no active paid sub (workspace is on Free or
 * a Custom plan). The customer billing portal redirect picks the right
 * gateway based on this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('payment_gateway', 32)->nullable()->after('stripe_subscription_id');
            $table->string('paypal_subscription_id')->nullable()->index()->after('payment_gateway');
            $table->string('razorpay_subscription_id')->nullable()->index()->after('paypal_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'payment_gateway',
                'paypal_subscription_id',
                'razorpay_subscription_id',
            ]);
        });
    }
};
