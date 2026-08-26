<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('scoring migrations roll back cleanly and re-up without error', function () {
    expect(Schema::hasTable('visitor_page_views'))->toBeTrue();
    expect(Schema::hasColumn('conversations', 'lead_score'))->toBeTrue();
    expect(Schema::hasColumn('conversations', 'lead_score_bucket'))->toBeTrue();
    expect(Schema::hasColumn('conversations', 'lead_score_updated_at'))->toBeTrue();
    expect(Schema::hasColumn('conversations', 'lead_score_reasons'))->toBeTrue();

    $migrations = [
        'database/migrations/2026_05_17_192247_add_lead_score_reasons_to_conversations.php',
        'database/migrations/2026_05_17_192131_add_workspace_index_to_visitor_page_views.php',
        'database/migrations/2026_05_17_175936_add_lead_score_to_conversations.php',
        'database/migrations/2026_05_17_175935_create_visitor_page_views_table.php',
    ];

    foreach ($migrations as $path) {
        $migration = include base_path($path);
        $migration->down();
    }

    expect(Schema::hasTable('visitor_page_views'))->toBeFalse();
    expect(Schema::hasColumn('conversations', 'lead_score'))->toBeFalse();
    expect(Schema::hasColumn('conversations', 'lead_score_bucket'))->toBeFalse();
    expect(Schema::hasColumn('conversations', 'lead_score_updated_at'))->toBeFalse();
    expect(Schema::hasColumn('conversations', 'lead_score_reasons'))->toBeFalse();

    // Re-apply in reverse order so the schema is back to the test-suite
    // baseline. RefreshDatabase handles cleanup between tests; this just
    // ensures we don't leave the next assertion-set in a broken schema.
    foreach (array_reverse($migrations) as $path) {
        $migration = include base_path($path);
        $migration->up();
    }

    expect(Schema::hasTable('visitor_page_views'))->toBeTrue();
    expect(Schema::hasColumn('conversations', 'lead_score'))->toBeTrue();
});

test('visitor_page_views table has all expected indexes', function () {
    $driver = DB::getDriverName();

    if ($driver === 'sqlite') {
        $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='visitor_page_views'"))
            ->pluck('name')
            ->all();
    } elseif ($driver === 'mysql' || $driver === 'mariadb') {
        $indexes = collect(DB::select('SHOW INDEX FROM visitor_page_views'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();
    } elseif ($driver === 'pgsql') {
        $indexes = collect(DB::select("SELECT indexname AS name FROM pg_indexes WHERE tablename = 'visitor_page_views'"))
            ->pluck('name')
            ->all();
    } else {
        $this->markTestSkipped("Unsupported driver: $driver");
    }

    $joined = strtolower(implode('|', $indexes));

    expect($joined)->toContain('visitor_id');
    expect($joined)->toContain('agent_id');
    expect($joined)->toContain('viewed_at');
    expect($joined)->toContain('workspace_id');
    expect($joined)->toContain('conversation_id');
});
