<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks when this user last acknowledged the changelog. The
     * What's-new banner on the admin dashboard reads it and
     * compares against the latest published entry's released_at.
     * Set lazily on banner dismissal so existing users don't get
     * a flood on the first load post-deploy.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('last_changelog_seen_at')->nullable()->after('default_workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_changelog_seen_at');
        });
    }
};
