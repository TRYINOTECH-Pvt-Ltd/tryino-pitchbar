<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align subscriptions / subscription_items / tickets to timestampTz
 * (rest of the schema is already on TZ-aware timestamps).
 * SQLite is dynamic-typed, no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite stores timestamps as text; the column type is
            // declarative only. No schema change needed.
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestampTz('created_at')->nullable()->change();
            $table->timestampTz('updated_at')->nullable()->change();
            $table->timestampTz('trial_ends_at')->nullable()->change();
            $table->timestampTz('ends_at')->nullable()->change();
        });

        Schema::table('subscription_items', function (Blueprint $table) {
            $table->timestampTz('created_at')->nullable()->change();
            $table->timestampTz('updated_at')->nullable()->change();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestampTz('created_at')->nullable()->change();
            $table->timestampTz('updated_at')->nullable()->change();
            $table->timestampTz('resolved_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
            $table->timestamp('resolved_at')->nullable()->change();
        });

        Schema::table('subscription_items', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
            $table->timestamp('trial_ends_at')->nullable()->change();
            $table->timestamp('ends_at')->nullable()->change();
        });
    }
};
