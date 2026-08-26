<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Postgres-only: a lint view that surfaces tables which look multi-tenant
 * (have an `agent_id` or `workspace_id` column) but are missing the
 * matching FK / index. Used by /tenancy audits and as a sanity check.
 *
 * Querying this view should return ZERO rows. If it does not, the
 * migration set is leaking multi-tenant scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE OR REPLACE VIEW tenant_check AS
            SELECT
                c.table_name,
                c.column_name,
                CASE
                    WHEN tc.constraint_name IS NULL THEN false
                    ELSE true
                END AS has_foreign_key
            FROM information_schema.columns c
            LEFT JOIN information_schema.key_column_usage kcu
                ON kcu.table_schema = c.table_schema
                AND kcu.table_name = c.table_name
                AND kcu.column_name = c.column_name
            LEFT JOIN information_schema.table_constraints tc
                ON tc.constraint_name = kcu.constraint_name
                AND tc.constraint_type = \'FOREIGN KEY\'
            WHERE c.table_schema = \'public\'
                AND c.column_name IN (\'workspace_id\', \'agent_id\')
                AND c.table_name NOT IN (\'workspaces\', \'agents\')
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP VIEW IF EXISTS tenant_check');
    }
};
