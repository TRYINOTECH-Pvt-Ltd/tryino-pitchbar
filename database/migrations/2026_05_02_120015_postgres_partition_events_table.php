<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Postgres-only: convert `events` to a RANGE-partitioned table by month.
 *
 * Approach:
 *  1. Rename the existing `events` table to `events_legacy`.
 *  2. Create a new partitioned `events` table with the same schema.
 *  3. Create the partition for the current month + next 3 months.
 *  4. Copy any rows from `events_legacy` (typically empty in fresh installs).
 *  5. Drop `events_legacy`.
 *
 * Skipped on non-Postgres drivers — the regular `events` table from
 * the previous migration is used as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE events RENAME TO events_legacy');
        DB::statement('
            CREATE TABLE events (
                id BIGSERIAL,
                workspace_id UUID NULL REFERENCES workspaces(id) ON DELETE SET NULL,
                agent_id UUID NULL REFERENCES agents(id) ON DELETE SET NULL,
                conversation_id UUID NULL REFERENCES conversations(id) ON DELETE SET NULL,
                kind VARCHAR(255) NOT NULL,
                payload JSONB NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
        ');

        DB::statement('CREATE INDEX events_workspace_kind_created_idx ON events (workspace_id, kind, created_at DESC)');
        DB::statement('CREATE INDEX events_agent_created_idx ON events (agent_id, created_at DESC)');

        $now = new DateTimeImmutable('first day of this month 00:00:00');
        for ($i = 0; $i < 4; $i++) {
            $start = $now->modify("+{$i} months")->format('Y-m-01');
            $end = $now->modify('+'.($i + 1).' months')->format('Y-m-01');
            $name = 'events_p'.$now->modify("+{$i} months")->format('Y_m');
            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF events FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $start,
                $end,
            ));
        }

        DB::statement('INSERT INTO events SELECT * FROM events_legacy');
        DB::statement('DROP TABLE events_legacy');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TABLE IF EXISTS events CASCADE');
        DB::statement('
            CREATE TABLE events (
                id BIGSERIAL PRIMARY KEY,
                workspace_id UUID NULL REFERENCES workspaces(id) ON DELETE SET NULL,
                agent_id UUID NULL REFERENCES agents(id) ON DELETE SET NULL,
                conversation_id UUID NULL REFERENCES conversations(id) ON DELETE SET NULL,
                kind VARCHAR(255) NOT NULL,
                payload JSONB NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        ');
    }
};
