<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('anonymous_id', 64)->index();
            $table->string('ip_hash', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('ua', 500)->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->integer('visit_count')->default(1);
            $table->timestampsTz();

            $table->unique(['agent_id', 'anonymous_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('visitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('page_url', 1000)->nullable();
            $table->string('lang', 8)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->boolean('is_lead')->default(false);
            $table->boolean('is_playground')->default(false);
            $table->uuid('variant_id')->nullable();
            $table->json('attribution')->nullable();
            $table->timestampsTz();

            $table->index(['agent_id', 'started_at']);
            $table->index(['agent_id', 'is_lead']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->json('citations')->nullable();
            $table->float('confidence')->nullable();
            $table->integer('tokens_in')->nullable();
            $table->integer('tokens_out')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->string('model')->nullable();
            $table->integer('feedback')->nullable();
            $table->text('feedback_reason')->nullable();
            $table->timestampsTz();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('visitors');
    }
};
