<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // When ON (default), the widget silently dispatches a crawl
            // for any new page a visitor lands on. Owners get a passive
            // knowledge base without ever clicking "Add source".
            $table->boolean('auto_index_visited_pages')->default(true)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('auto_index_visited_pages');
        });
    }
};
