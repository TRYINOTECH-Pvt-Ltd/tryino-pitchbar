<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_page_views', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('visitor_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 500);
            $table->string('title', 200)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->timestampTz('viewed_at');
            $table->timestampsTz();

            $table->index('viewed_at');
            $table->index(['visitor_id', 'viewed_at']);
            $table->index(['agent_id', 'viewed_at']);
            $table->index(['conversation_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_page_views');
    }
};
