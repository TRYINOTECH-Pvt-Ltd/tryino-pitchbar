<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('site_title')->nullable()->after('pitchbar_brand_label');
            $table->string('header_logo_path')->nullable()->after('site_title');
            $table->string('footer_logo_path')->nullable()->after('header_logo_path');
            $table->string('dashboard_logo_path')->nullable()->after('footer_logo_path');
            $table->string('favicon_path')->nullable()->after('dashboard_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'site_title',
                'header_logo_path',
                'footer_logo_path',
                'dashboard_logo_path',
                'favicon_path',
            ]);
        });
    }
};
