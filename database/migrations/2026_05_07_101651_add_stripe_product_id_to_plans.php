<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the Stripe Product id alongside the existing Stripe Price id.
 * Stripe Products are mutable (we update name + metadata when admin
 * renames a plan), but Stripe Prices are not (a price change forces
 * a new Price + archive of the old). Storing both means we can keep
 * one Product across price rotations and only churn Prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable()->after('stripe_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('stripe_product_id');
        });
    }
};
