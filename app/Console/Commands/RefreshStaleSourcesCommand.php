<?php

namespace App\Console\Commands;

use App\Jobs\Crawl\CrawlSourceJob;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Re-crawls sources whose `last_synced_at` is older than --days.
 *
 * Sources are crawled once at create time and never touched again — but real
 * sites change: prices update, products go out of stock, content gets edited.
 * This command quietly refreshes them on a schedule so the agent's answers
 * don't drift away from reality.
 *
 * Wired into the scheduler via routes/console.php.
 */
class RefreshStaleSourcesCommand extends Command
{
    protected $signature = 'pitchbar:refresh-stale-sources
        {--days=7 : Re-crawl sources synced more than N days ago}
        {--limit=200 : Maximum sources to dispatch in this run (back-pressure for CF Browser Rendering)}
        {--dry-run : Print what would be queued, don\'t dispatch}';

    protected $description = 'Re-crawl sources whose content is older than N days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($days);

        $stale = Source::query()->withoutGlobalScopes()
            ->where('status', 'indexed')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $cutoff);
            })
            ->orderBy('last_synced_at')
            ->limit($limit)
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No sources older than {$days} day(s).");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d stale source(s) older than %d day(s).',
            $dryRun ? 'Would refresh' : 'Refreshing',
            $stale->count(),
            $days,
        ));

        foreach ($stale as $source) {
            $url = $source->config['url'] ?? '(no url)';
            $this->line("  · {$source->id} · {$url}");

            if ($dryRun) {
                continue;
            }

            $source->forceFill(['status' => 'pending', 'error' => null])->save();
            CrawlSourceJob::dispatch($source->id)->onQueue('crawl');
        }

        return self::SUCCESS;
    }
}
