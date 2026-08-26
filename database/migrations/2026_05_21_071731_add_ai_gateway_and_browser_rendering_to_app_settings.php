<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move the last two Cloudflare-related knobs off `.env` and into
 * AppSetting. Buyer-reported (2026-05-21): customers shouldn't need
 * to edit `.env` to flip AI Gateway routing or disable Browser
 * Rendering. Every other Cloudflare credential already lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('cloudflare_ai_gateway_url', 500)->nullable();
            $table->boolean('cloudflare_browser_rendering')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['cloudflare_ai_gateway_url', 'cloudflare_browser_rendering']);
        });
    }
};
