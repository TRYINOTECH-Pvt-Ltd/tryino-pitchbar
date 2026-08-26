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
        Schema::table('sources', function (Blueprint $table) {
            // "Answer on every page" — global/company facts (address,
            // opening hours, contact) that must be reachable from any
            // page, not walled to the one page they were crawled from.
            // Retriever gives these chunks the current-page-equivalent
            // lift on every turn.
            $table->boolean('is_global')->default(false)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('is_global');
        });
    }
};
