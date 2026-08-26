<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pluggable payment gateways: PayPal + Razorpay alongside Stripe.
 *
 * `*_enabled` flags are admin-toggleable. Stripe stays enabled by default
 * (existing installs); PayPal + Razorpay default to false until the admin
 * enters credentials and flips the switch. Sensitive secrets are encrypted
 * at rest via the model's `encrypted` cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            // Master enable flags. The customer billing page only lists
            // gateways that are both `*_enabled=true` AND have credentials
            // configured, so an in-progress setup never leaks a half-wired
            // gateway to customers.
            $table->boolean('stripe_enabled')->default(true)->after('cashier_currency');
            $table->boolean('paypal_enabled')->default(false)->after('stripe_enabled');
            $table->boolean('razorpay_enabled')->default(false)->after('paypal_enabled');

            // PayPal — REST API client credentials. `mode` toggles between
            // sandbox.paypal.com and api-m.paypal.com base URLs.
            $table->string('paypal_mode', 16)->nullable()->after('razorpay_enabled');
            $table->text('paypal_client_id')->nullable()->after('paypal_mode');
            $table->text('paypal_client_secret')->nullable()->after('paypal_client_id');
            $table->text('paypal_webhook_id')->nullable()->after('paypal_client_secret');

            // Razorpay — HTTP Basic auth (key_id : key_secret). Webhook
            // secret is configured in the Razorpay dashboard, mirrored here
            // for HMAC verification of incoming webhook calls.
            $table->text('razorpay_key_id')->nullable()->after('paypal_webhook_id');
            $table->text('razorpay_key_secret')->nullable()->after('razorpay_key_id');
            $table->text('razorpay_webhook_secret')->nullable()->after('razorpay_key_secret');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_enabled',
                'paypal_enabled',
                'razorpay_enabled',
                'paypal_mode',
                'paypal_client_id',
                'paypal_client_secret',
                'paypal_webhook_id',
                'razorpay_key_id',
                'razorpay_key_secret',
                'razorpay_webhook_secret',
            ]);
        });
    }
};
