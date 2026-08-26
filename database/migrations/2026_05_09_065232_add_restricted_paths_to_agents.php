<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror of `allowed_origins` but for URL paths the widget should
     * NOT mount on. Used by buyers to disable Pitchbar on their own
     * `/admin`, `/checkout`, `/account` flows without touching code.
     *
     * Each entry is a string with optional `*` wildcards:
     *   /admin/*    — matches any path under /admin
     *   /checkout   — exact path
     *   /a/b/*      — any path under /a/b
     *
     * NULL or empty array = no path restrictions (widget mounts on
     * every page that already passes the allowed_origins check).
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('restricted_paths')->nullable()->after('allowed_origins');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('restricted_paths');
        });
    }
};
