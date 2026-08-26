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
        Schema::create('turn_traces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->uuid('conversation_id')->index();
            // Assistant message id minted for the turn. Not a FK — error
            // turns never persist a messages row, but their trace must
            // survive (failures are exactly when forensics matter).
            $table->uuid('message_id')->nullable();
            // llm | curated | human_shortcut | human_pending | takeover | error
            $table->string('kind', 32)->default('llm');
            $table->json('payload');
            $table->timestampTz('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('turn_traces');
    }
};
