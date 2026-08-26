<?php

namespace App\Console\Commands;

use App\Jobs\Crawl\IngestGoogleDocJob;
use App\Jobs\Crawl\IngestNotionPageJob;
use App\Jobs\Crawl\SyncGoogleSheetJob;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Hourly delta sync for OAuth sources (Notion pages, Google Docs).
 *
 * Unlike crawled websites — which we refresh weekly — Notion/Drive
 * content changes frequently and the user expects the agent to know
 * about edits the same hour. This command picks all 'notion' /
 * 'google_doc' sources whose last_synced_at is older than --hours and
 * dispatches the right ingest job for each.
 *
 * Wired into the scheduler in routes/console.php to run every hour.
 */
class SyncOAuthSourcesCommand extends Command
{
    protected $signature = 'pitchbar:sync-oauth-sources
        {--hours=1 : Re-sync sources older than N hours}
        {--limit=500 : Maximum sources to dispatch this run}
        {--types= : Comma-separated subset of types (default: notion,google_doc)}
        {--dry-run : Print what would be queued without dispatching}';

    protected $description = 'Re-ingest Notion / Google Doc sources whose content might be stale';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $typesOption = (string) ($this->option('types') ?? '');
        $types = $typesOption === ''
            ? ['notion', 'google_doc', 'google_sheet']
            : array_filter(array_map('trim', explode(',', $typesOption)));

        $cutoff = now()->subHours($hours);

        $stale = Source::query()->withoutGlobalScopes()
            ->whereIn('type', $types)
            ->where('status', 'indexed')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $cutoff);
            })
            ->orderBy('last_synced_at')
            ->limit($limit)
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No OAuth sources older than {$hours}h.");

            return self::SUCCESS;
        }

        $byType = $stale->groupBy('type')->map->count();
        $this->info(sprintf(
            '%s %d source(s) — %s',
            $dryRun ? 'Would re-sync' : 'Re-syncing',
            $stale->count(),
            $byType->map(fn ($n, $type) => "{$type}={$n}")->implode(', '),
        ));

        foreach ($stale as $source) {
            $this->line("  · {$source->type} · {$source->id}");

            if ($dryRun) {
                continue;
            }

            $source->forceFill(['status' => 'pending', 'error' => null])->save();

            match ($source->type) {
                'notion' => IngestNotionPageJob::dispatch($source->id)->onQueue('crawl'),
                'google_doc' => IngestGoogleDocJob::dispatch($source->id)->onQueue('crawl'),
                'google_sheet' => SyncGoogleSheetJob::dispatch($source->id)->onQueue('crawl'),
                default => $this->warn("  unknown type: {$source->type} (skipped)"),
            };
        }

        return self::SUCCESS;
    }
}
