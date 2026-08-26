<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_page_views', function (Blueprint $table) {
            $table->index(['workspace_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('visitor_page_views', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'viewed_at']);
        });
    }
};
