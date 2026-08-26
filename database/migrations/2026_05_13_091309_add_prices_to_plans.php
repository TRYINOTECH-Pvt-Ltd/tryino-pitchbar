<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency support. Each plan can ship a `prices` JSON map
 * keyed by ISO 4217 currency code → minor-unit integer (cents-ish):
 *
 *     {
 *         "usd": 4900,     // $49.00
 *         "eur": 4500,     // €45.00
 *         "inr": 399000,   // ₹3990.00 (INR has 2 decimal places too)
 *         ...
 *     }
 *
 * Backwards-compat: the existing `price_cents` column stays. On
 * migrate the prior `price_cents` value back-fills as the `usd`
 * entry in the new map. Existing readers continue to work; new
 * readers prefer `prices[currency]` and fall back to `price_cents`.
 *
 * Default currency for the workspace lives on the new
 * `workspaces.preferred_currency` column — driven from the visitor's
 * choice on the pricing page if any, else CF-IPCountry → Accept-Language
 * → 'usd'.
 *
 * Stripe per-currency Prices are minted lazily by StripeProductSync
 * the first time a buyer attempts checkout in that currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // `json` on MySQL/Postgres; `text` on SQLite (Laravel handles
            // the dialect mapping). Eloquent casts to array.
            $table->json('prices')->nullable();
            // Per-currency Stripe Price IDs, also lazy-minted. Shape:
            // { "usd": "price_xxx", "eur": "price_yyy", … }
            $table->json('stripe_price_ids')->nullable();
        });

        // Back-fill the new `prices` JSON from the existing
        // `price_cents` column so old plans keep selling in USD without
        // a manual edit.
        DB::table('plans')->get(['id', 'price_cents'])->each(function ($row) {
            DB::table('plans')->where('id', $row->id)->update([
                'prices' => json_encode(['usd' => (int) $row->price_cents]),
            ]);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('preferred_currency', 8)->default('usd');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['prices', 'stripe_price_ids']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('preferred_currency');
        });
    }
};
