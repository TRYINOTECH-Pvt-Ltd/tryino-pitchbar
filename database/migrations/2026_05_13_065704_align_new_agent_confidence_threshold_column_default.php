<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align the `agents.confidence_threshold` column default with the
 * runtime expectation for Cloudflare Workers AI (the documented default
 * provider). Pre-fix the column default was 0.78, which is correct for
 * OpenAI's text-embedding-3-small but silently filters every relevant
 * match on Cloudflare's bge-base-en-v1.5 (ANN cosine typically 0.50-0.65).
 *
 * AgentController already resolves the threshold from config at create
 * time, so the column default is only a safety net — but a wrong default
 * means a tinker / factory / raw insert produces the broken agent
 * (which is exactly how I caught this during E2E testing today).
 *
 * Postgres / MySQL both accept `change()`. Laravel 13 ships the
 * platform-aware shim built in; no doctrine/dbal needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->float('confidence_threshold')->default(0.5)->change();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->float('confidence_threshold')->default(0.78)->change();
        });
    }
};
