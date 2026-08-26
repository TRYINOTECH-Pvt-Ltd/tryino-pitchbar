<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsr_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('action', 16);
            $table->string('lookup_email')->nullable();
            $table->uuid('lookup_visitor_id')->nullable();
            $table->string('lookup_anonymous_id')->nullable();
            $table->string('source', 16);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->json('matched_visitor_ids')->nullable();
            $table->text('result_payload')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['workspace_id', 'lookup_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsr_requests');
    }
};
