<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-created custom content pages. Public surface at /p/{slug}.
 * Buyer ask: a tenant operator wants to add an About / Company /
 * Product page to the marketing footer without editing Blade files.
 *
 * Content is plain markdown — same renderer the documentation pages
 * use. No raw HTML, no inline script. Unpublished pages 404 on the
 * public route so admins can write drafts safely.
 *
 * `sort_order` drives the auto-appended footer link order; lower
 * numbers render first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->longText('content_markdown');
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
