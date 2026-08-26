<?php

namespace App\Console\Commands;

use App\Jobs\Analytics\SuggestCuratedAnswerForGapJob;
use App\Models\ContentGap;
use Illuminate\Console\Command;

/**
 * For each ContentGap that's been hit at least N times and is still 'open',
 * dispatch the suggestion job. Drives the self-improvement loop on a
 * weekly cron (see routes/console.php).
 */
class SuggestFromGapsCommand extends Command
{
    protected $signature = 'pitchbar:suggest-from-gaps
        {--min-occurrences=3 : Minimum repeat count before we draft a suggestion}
        {--limit=50 : Maximum gaps to dispatch this run}';

    protected $description = 'Draft a CuratedAnswer suggestion for recurring unanswered questions';

    public function handle(): int
    {
        $minOcc = max(1, (int) $this->option('min-occurrences'));
        $limit = max(1, (int) $this->option('limit'));

        $gaps = ContentGap::query()->withoutGlobalScopes()
            ->where('status', 'open')
            ->where('occurrences', '>=', $minOcc)
            ->orderByDesc('occurrences')
            ->limit($limit)
            ->get();

        if ($gaps->isEmpty()) {
            $this->info("No gaps with >= {$minOcc} occurrences.");

            return self::SUCCESS;
        }

        foreach ($gaps as $gap) {
            $this->line("  · gap {$gap->id} ({$gap->occurrences}×) — {$gap->question}");
            SuggestCuratedAnswerForGapJob::dispatch($gap->id)->onQueue('default');
        }

        $this->info('Dispatched suggestions for '.$gaps->count().' gap(s).');

        return self::SUCCESS;
    }
}
