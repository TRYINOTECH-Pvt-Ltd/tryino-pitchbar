<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Events: regular table on non-Postgres drivers; partitioned on Postgres
        // (the partitioning is added in a follow-up Postgres-only migration).
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->json('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['workspace_id', 'kind', 'created_at']);
            $table->index(['agent_id', 'created_at']);
        });

        Schema::create('content_gaps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->string('question_hash', 64)->index();
            $table->integer('occurrences')->default(1);
            $table->timestampTz('last_seen_at')->nullable();
            $table->string('status')->default('open');
            $table->timestampsTz();

            $table->unique(['agent_id', 'question_hash']);
            $table->index(['agent_id', 'status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_gaps');
        Schema::dropIfExists('events');
    }
};
