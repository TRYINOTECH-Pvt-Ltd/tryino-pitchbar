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
        Schema::table('conversations', function (Blueprint $table) {
            // Visitor's post-conversation rating. 'positive' / 'negative'.
            // Stored as a varchar (not enum) so future rating values
            // (e.g. 'neutral' or numeric 1–5) can land without a
            // schema change.
            $table->string('satisfaction', 16)->nullable();
            $table->timestamp('satisfaction_at')->nullable();
            $table->text('satisfaction_comment')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['satisfaction', 'satisfaction_at', 'satisfaction_comment']);
        });
    }
};
