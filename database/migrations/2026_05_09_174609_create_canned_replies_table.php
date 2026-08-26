<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('canned_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            // Short label the operator searches by ("Reset password",
            // "Refund policy", "Shipping ETA"). Capped at 80 to keep
            // the picker dropdown readable.
            $table->string('label', 80);

            // The actual reply text. Markdown is allowed (the message
            // pipeline already renders MarkdownLite for replies).
            $table->text('content');

            // Manual ordering — operators want their most-used replies
            // at the top regardless of alphabetical order. Lowest first.
            $table->unsignedSmallInteger('position')->default(0);

            // Audit trail for canned replies created by team members.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Compound index for the picker — workspace_id is the
            // global scope's filter; position drives the visible order.
            $table->index(['workspace_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
    }
};
