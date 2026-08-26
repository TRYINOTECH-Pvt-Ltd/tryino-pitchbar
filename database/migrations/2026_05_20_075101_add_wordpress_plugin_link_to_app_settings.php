<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('wordpress_plugin_download_url', 500)->nullable();
            $table->text('wordpress_plugin_help_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['wordpress_plugin_download_url', 'wordpress_plugin_help_text']);
        });
    }
};
