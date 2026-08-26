<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surfaces WordPress plugin connectivity on the /app/integrations page.
 *
 * Snapshot of the most recent plugin-side call carrying an agent_id —
 * stamped by every WP controller that touches this agent (posts/sync,
 * products/sync, coupons/sync, posts/changed, products/changed). The
 * column is read by Admin\IntegrationController to render the
 * "WordPress connected" rows on the integrations page.
 *
 * Shape:
 * {
 *   "site_url": "https://shop.example.com",
 *   "plugin_version": "2.0.4",
 *   "wordpress_version": "6.6",
 *   "woocommerce_active": true,
 *   "last_seen_at": "2026-05-13T04:30:00+00:00"
 * }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('wp_integration')->nullable()->after('lead_form_fields');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('wp_integration');
        });
    }
};
