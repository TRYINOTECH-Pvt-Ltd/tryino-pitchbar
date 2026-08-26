<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // When the visitor uses "Clear conversation" in the widget,
            // we stamp this column so /init filters out messages with
            // created_at <= cleared_at. Keeping rows around (vs hard
            // deleting) preserves analytics + lead linkage and lets us
            // later expose "show full history" in the admin if we want.
            $table->timestampTz('cleared_at')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('cleared_at');
        });
    }
};
