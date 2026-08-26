<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level toggle for the public marketing site. When false,
 * unauthenticated visitors hitting `/`, `/pricing`, `/how-it-works`,
 * `/integrations`, `/changelog`, or `/documentation/*` are
 * redirected to `/login`. Useful for buyers running a private SaaS
 * who don't want a public sales pitch on their domain.
 *
 * Default true so every existing install keeps its current
 * behaviour — opt-in disable via /settings/branding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->boolean('marketing_site_enabled')
                ->default(true)
                ->after('dashboard_brand_display');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('marketing_site_enabled');
        });
    }
};
