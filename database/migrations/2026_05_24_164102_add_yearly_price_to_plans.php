<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Annual price in the plan's canonical currency. Optional —
            // when null the customer-facing billing page hides the
            // "Annual" toggle for this plan. Admin sets both prices
            // from the same form so a single plan row covers both
            // intervals, instead of duplicating the row.
            $table->integer('yearly_price_cents')->nullable()->after('price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('yearly_price_cents');
        });
    }
};
