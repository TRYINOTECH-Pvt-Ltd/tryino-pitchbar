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
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('header_brand_display', 24)->nullable()->after('favicon_path');
            $table->string('footer_brand_display', 24)->nullable()->after('header_brand_display');
            $table->string('dashboard_brand_display', 24)->nullable()->after('footer_brand_display');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'header_brand_display',
                'footer_brand_display',
                'dashboard_brand_display',
            ]);
        });
    }
};
