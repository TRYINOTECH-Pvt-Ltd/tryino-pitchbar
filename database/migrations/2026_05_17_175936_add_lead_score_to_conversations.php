<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_score')->default(0)->after('satisfaction_comment');
            $table->string('lead_score_bucket', 8)->default('low')->after('lead_score');
            $table->timestampTz('lead_score_updated_at')->nullable()->after('lead_score_bucket');

            $table->index(['agent_id', 'lead_score']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'lead_score']);
            $table->dropColumn(['lead_score', 'lead_score_bucket', 'lead_score_updated_at']);
        });
    }
};
