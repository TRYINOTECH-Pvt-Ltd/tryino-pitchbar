<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live human takeover: a workspace member can "claim" an in-flight
 * conversation, switching the bot off for that session and replying as
 * themselves. claimed_at records when, claimed_by_user_id who.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            // users.id is auto-incrementing bigint, not UUID — match it.
            $table->unsignedBigInteger('claimed_by_user_id')->nullable()->after('variant_id');
            $table->timestampTz('claimed_at')->nullable()->after('claimed_by_user_id');

            $table->index(['agent_id', 'claimed_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['agent_id', 'claimed_by_user_id']);
            $table->dropColumn(['claimed_by_user_id', 'claimed_at']);
        });
    }
};
