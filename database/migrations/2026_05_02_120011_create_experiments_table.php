<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->string('status')->default('draft');
            $table->json('traffic_split')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->timestampsTz();

            $table->index(['agent_id', 'status']);
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('config')->nullable();
            $table->integer('weight')->default(50);
            $table->timestampsTz();
        });

        Schema::create('experiment_assignments', function (Blueprint $table) {
            $table->foreignUuid('visitor_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experiment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('assigned_at');

            $table->primary(['visitor_id', 'experiment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_assignments');
        Schema::dropIfExists('variants');
        Schema::dropIfExists('experiments');
    }
};
