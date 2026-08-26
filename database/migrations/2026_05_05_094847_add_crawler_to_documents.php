<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Records WHICH crawler engine extracted this document — useful
            // for "why did this page fail?" debugging. Nullable so non-HTML
            // documents (pasted text, Notion, Google Doc, uploads) leave
            // it empty.
            $table->string('crawler', 64)->nullable()->after('lang');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('crawler');
        });
    }
};
