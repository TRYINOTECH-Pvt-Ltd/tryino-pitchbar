<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill legacy CSAT vocabulary. Widget + SatisfactionController
 * always wrote 'positive' / 'negative', but the analytics reader
 * historically compared against 'good' / 'bad' — and a small number
 * of seeders / older code paths populated rows with the 'good' / 'bad'
 * flavour. Reader now accepts both, but normalising at-rest keeps
 * future queries simple + the audit trail consistent.
 *
 * No down() backfill — once normalised we're not interested in
 * recreating the legacy split. Down is a no-op so a rollback doesn't
 * silently undo data hygiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversations')
            ->where('satisfaction', 'good')
            ->update(['satisfaction' => 'positive']);

        DB::table('conversations')
            ->where('satisfaction', 'bad')
            ->update(['satisfaction' => 'negative']);
    }

    public function down(): void
    {
        // No-op: normalisation is one-way. See class docblock.
    }
};
