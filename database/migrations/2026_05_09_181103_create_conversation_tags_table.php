<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversation_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            // Hex color (#RRGGBB) used for the chip background. The
            // settings UI offers a curated palette; arbitrary hex is
            // accepted but normalized server-side.
            $table->string('color', 7)->default('#64748b');
            $table->timestamps();
            // Workspace + label uniqueness avoids two tags with the
            // same name in the same workspace, which would confuse
            // operators in the picker.
            $table->unique(['workspace_id', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_tags');
    }
};
