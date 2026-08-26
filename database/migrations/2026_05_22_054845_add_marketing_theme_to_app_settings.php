<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            // Operator-selected marketing-site theme slug. Resolves to a
            // bundle of Inertia pages under
            // `resources/js/pages/marketing-themes/{slug}/`. Default
            // `harvest` preserves the legacy layout shipped before the
            // pluggable theme system landed (v2.0.0).
            $table->string('marketing_theme', 64)->default('harvest')->after('marketing_widget_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('marketing_theme');
        });
    }
};
