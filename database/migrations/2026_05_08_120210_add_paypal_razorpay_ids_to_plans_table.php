<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors `stripe_product_id` / `stripe_price_id` for the new gateways.
 *
 * - PayPal models a billing plan as Product → Plan, mirroring Stripe's
 *   Product → Price split. We persist both ids.
 * - Razorpay's "plan" is a single resource; one id is enough.
 *
 * All columns nullable: free / custom plans skip every gateway entirely
 * and a plan only gets populated under the gateway(s) that have synced
 * it (lazy-on-first-checkout, same pattern as `stripe_price_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('paypal_product_id')->nullable()->after('stripe_product_id');
            $table->string('paypal_plan_id')->nullable()->after('paypal_product_id');
            $table->string('razorpay_plan_id')->nullable()->after('paypal_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'paypal_product_id',
                'paypal_plan_id',
                'razorpay_plan_id',
            ]);
        });
    }
};
