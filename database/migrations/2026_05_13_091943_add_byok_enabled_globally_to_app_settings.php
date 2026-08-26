<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App-level BYOK toggle. When ON, every workspace MUST supply its own
 * upstream keys. When OFF, platform keys are the default; per-user
 * `byok_enabled` override can still grant BYOK access to specific users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('app_settings', 'byok_enabled_globally')) {
                $table->boolean('byok_enabled_globally')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            if (Schema::hasColumn('app_settings', 'byok_enabled_globally')) {
                $table->dropColumn('byok_enabled_globally');
            }
        });
    }
};
