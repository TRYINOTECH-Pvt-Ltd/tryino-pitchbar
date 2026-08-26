<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('header_logo_dark_path')->nullable()->after('header_logo_path');
            $table->string('footer_logo_dark_path')->nullable()->after('footer_logo_path');
            $table->string('dashboard_logo_dark_path')->nullable()->after('dashboard_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'header_logo_dark_path',
                'footer_logo_dark_path',
                'dashboard_logo_dark_path',
            ]);
        });
    }
};
