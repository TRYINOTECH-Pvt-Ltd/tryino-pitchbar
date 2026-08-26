<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable copy for the four landing /integrations cards
 * (Notion, Google Docs, Slack, Stripe). Operators wanted to retitle
 * the cards / reword the descriptions without forking the code
 * (client report 2026-05-25). Stored as JSON because the shape is
 * `[{name, category, tagline, description}, ...]` — too nested for
 * dedicated columns, low write volume, never indexed against.
 *
 * Nullable + null-default = clean fall-through to the hardcoded
 * defaults in `IntegrationCardsContent::defaults()` for fresh
 * installs and any field the admin chose not to override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->json('integration_cards')->nullable()->after('integrations_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('integration_cards');
        });
    }
};
