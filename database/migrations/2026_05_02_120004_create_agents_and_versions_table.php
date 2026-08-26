<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('language_default', 8)->default('en');
            $table->json('persona')->nullable();
            $table->json('theme')->nullable();
            $table->json('allowed_origins')->nullable();
            $table->text('system_prompt')->nullable();
            $table->json('guardrails')->nullable();
            $table->float('confidence_threshold')->default(0.78);
            $table->boolean('is_published')->default(false);
            $table->uuid('published_version_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['workspace_id', 'is_published']);
        });

        Schema::create('agent_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['agent_id', 'created_at']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreign('published_version_id')->references('id')->on('agent_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });
        Schema::dropIfExists('agent_versions');
        Schema::dropIfExists('agents');
    }
};
