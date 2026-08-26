<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            // Admin-editable copy that lives on the auth-page side
            // panel (Aurora & Prism themes ship one). Buyer report:
            // hard-coded "marketing site" phrasing leaked through to
            // workspaces whose product is not a marketing surface.
            // NULL = fall back to the theme's bundled copy.
            $table->string('auth_aside_eyebrow', 120)->nullable()->after('pitchbar_brand_label');
            $table->string('auth_aside_heading', 200)->nullable()->after('auth_aside_eyebrow');
            $table->text('auth_aside_lede')->nullable()->after('auth_aside_heading');
            $table->text('auth_aside_bullets')->nullable()->after('auth_aside_lede');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['auth_aside_eyebrow', 'auth_aside_heading', 'auth_aside_lede', 'auth_aside_bullets']);
        });
    }
};
