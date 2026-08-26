<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Stable external identifier keyed by ingest source (e.g.
            // "wp:42" for a WordPress post). Lets delta sync locate the
            // existing Document in O(1) without guessing by URL.
            $table->string('external_id', 120)->nullable();
            $table->timestampTz('external_updated_at')->nullable();
            $table->index(['agent_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'external_id']);
            $table->dropColumn(['external_id', 'external_updated_at']);
        });
    }
};
