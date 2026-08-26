<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->json('pricing_faqs')->nullable()->after('marketing_home_content');
            $table->json('pricing_matrix')->nullable()->after('pricing_faqs');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['pricing_faqs', 'pricing_matrix']);
        });
    }
};
