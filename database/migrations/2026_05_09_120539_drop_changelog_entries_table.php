<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move the changelog out of the database. Entries now live in
 * `storage/app/private/changelog-entries.json`, seeded from
 * `database/changelog-entries/v*.md` source markdown shipped in
 * git. Same pattern as the internal Kanban board — `migrate:fresh`
 * during development never wipes release notes again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('changelog_entries');
    }

    public function down(): void
    {
        // Best-effort recreation if you really need to roll back. The
        // data won't come back — it lives in the JSON store now —
        // but the table shape is preserved so other migrations
        // depending on it could replay.
        Schema::create('changelog_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version', 32)->unique();
            $table->timestamp('released_at')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->string('title');
            $table->longText('body');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'released_at']);
        });
    }
};
