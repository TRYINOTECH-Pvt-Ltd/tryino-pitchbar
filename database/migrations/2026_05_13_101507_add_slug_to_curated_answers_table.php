<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * C3 follow-up: curated answers double as public KB articles.
 *
 * `slug` becomes the URL component for `/kb/{workspace-slug}/{slug}`
 * so a buyer pinning a curated answer also gets a public, citable
 * help-center page in one move. `kb_published` is the visibility
 * gate — false = answer still drives the chat but doesn't surface
 * on /kb. `kb_title` overrides the visible heading (defaults to
 * question_pattern when blank).
 *
 * Slug is unique per agent so two agents in the same workspace can
 * carry an identically-named article without colliding. Back-fill
 * pass derives a slug from the question pattern for every existing
 * row so the new column never sits null on a live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curated_answers', function (Blueprint $table) {
            $table->string('slug', 160)->nullable()->after('answer');
            $table->string('kb_title', 200)->nullable()->after('slug');
            $table->boolean('kb_published')->default(false)->after('kb_title');
        });

        // Back-fill slug + (optionally) published flag from existing rows.
        DB::table('curated_answers')
            ->orderBy('id')
            ->get(['id', 'question_pattern'])
            ->each(function ($row) {
                $base = Str::slug((string) $row->question_pattern);
                if ($base === '') {
                    $base = 'article-'.Str::random(6);
                }
                $slug = $base;
                $i = 2;
                while (DB::table('curated_answers')
                    ->where('slug', $slug)
                    ->where('id', '!=', $row->id)
                    ->exists()
                ) {
                    $slug = "{$base}-{$i}";
                    $i++;
                }
                DB::table('curated_answers')
                    ->where('id', $row->id)
                    ->update(['slug' => $slug]);
            });

        Schema::table('curated_answers', function (Blueprint $table) {
            $table->index(['agent_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('curated_answers', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'slug']);
            $table->dropColumn(['slug', 'kb_title', 'kb_published']);
        });
    }
};
