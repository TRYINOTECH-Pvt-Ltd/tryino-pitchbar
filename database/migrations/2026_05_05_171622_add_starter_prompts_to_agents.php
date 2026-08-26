<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Optional list of starter-prompt strings shown as clickable
            // chips in the widget's empty-state view. Customer-editable
            // via /app/agents/X/customize. Capped to a handful in the
            // request validator.
            $table->json('starter_prompts')->nullable()->after('guardrails');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('starter_prompts');
        });
    }
};
