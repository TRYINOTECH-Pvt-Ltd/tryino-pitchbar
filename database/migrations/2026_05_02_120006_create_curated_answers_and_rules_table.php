<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curated_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->text('question_pattern');
            $table->text('answer');
            $table->integer('priority')->default(0);
            $table->json('conditions')->nullable();
            $table->string('lang', 8)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->index(['agent_id', 'enabled', 'priority']);
        });

        Schema::create('cta_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('label');
            $table->string('kind');
            $table->json('conditions')->nullable();
            $table->json('target')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('priority')->default(0);
            $table->timestampsTz();

            $table->index(['agent_id', 'enabled', 'priority']);
        });

        Schema::create('behavior_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->json('conditions')->nullable();
            $table->json('action')->nullable();
            $table->foreignUuid('cta_rule_id')->nullable()->constrained('cta_rules')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->integer('priority')->default(0);
            $table->timestampsTz();

            $table->index(['agent_id', 'enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_rules');
        Schema::dropIfExists('cta_rules');
        Schema::dropIfExists('curated_answers');
    }
};
