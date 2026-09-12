<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * pgvector is optional. Cloudflare Vectorize / Qdrant installs
     * must boot on stock Postgres (e.g. postgres:16-alpine) which
     * does not ship the extension. Run outside a transaction so a
     * failed CREATE EXTENSION does not abort the rest of migrate.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (\Throwable) {
            // Extension not installed on this Postgres image.
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('DROP EXTENSION IF EXISTS vector');
        } catch (\Throwable) {
            //
        }
    }
};
