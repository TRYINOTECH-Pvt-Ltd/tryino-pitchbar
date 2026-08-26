<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Application-wide changelog entries authored by super_admins,
     * publishable to buyers via /changelog and /changelog.json.
     *
     * Platform-level: no workspace_id. One platform, one shared
     * history visible to every buyer. Status enum:
     *   - draft     — invisible to buyers; admins iterate.
     *   - published — visible on /changelog + counted by the
     *                 What's-new banner.
     *   - archived  — kept for forensic / linkback safety; not
     *                 listed publicly.
     */
    public function up(): void
    {
        Schema::create('changelog_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version', 32)->unique();
            $table->timestampTz('released_at')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('title');
            $table->longText('body');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_entries');
    }
};
