<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable overrides layered over the shipped lang/{locale}.json
 * files. The files stay the baseline (and the source of the canonical
 * key set); a row here wins at runtime for one (locale, key). Lets the
 * operator fix or fill any string in any language from the UI without a
 * redeploy.
 *
 * The key is the English source string (the JSON convention), which can
 * be a long sentence — too long to index directly across drivers — so
 * uniqueness is enforced on a sha1 of the key instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 16);
            $table->text('key');
            $table->char('key_sha1', 40);
            $table->text('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['locale', 'key_sha1']);
            $table->index('locale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};
