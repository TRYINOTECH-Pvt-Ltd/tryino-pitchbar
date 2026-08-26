<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->json('config')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['agent_id', 'status']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('text_path')->nullable();
            $table->string('lang', 8)->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampsTz();

            $table->index(['agent_id', 'content_hash']);
            $table->index(['source_id']);
        });

        Schema::create('chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->integer('ord')->default(0);
            $table->text('text');
            $table->integer('token_count')->default(0);
            $table->uuid('qdrant_point_id')->nullable();
            $table->timestampsTz();

            $table->index(['agent_id']);
            $table->index(['document_id', 'ord']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('sources');
    }
};
