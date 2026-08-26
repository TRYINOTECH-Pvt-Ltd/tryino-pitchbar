<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('site_type', 32)->nullable()->after('starter_prompts');
            $table->json('vertical_signals')->nullable()->after('site_type');
            $table->json('vertical_overrides')->nullable()->after('vertical_signals');
            $table->index('site_type');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['site_type']);
            $table->dropColumn(['site_type', 'vertical_signals', 'vertical_overrides']);
        });
    }
};
