<?php

use App\Jobs\LiveChat\ReleaseStaleHumanRequestsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily re-crawl of indexed sources older than 7 days. Avoids cf rate limits
// by capping the batch and stagger-dispatching inside CrawlSourceJob.
Schedule::command('pitchbar:refresh-stale-sources --days=7 --limit=200')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

// Self-healing indexing: auto-retry transiently-failed sources (rate
// limits, timeouts, 5xx) and rescue ones stranded mid-crawl, capped at
// 3 attempts per failure episode — so owners never have to babysit the
// Reindex button. Permanent errors are never auto-retried.
Schedule::command('pitchbar:retry-sources --limit=100')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Weekly self-improvement: turn recurring unanswered questions into draft
// curated answers for the workspace owner to approve.
Schedule::command('pitchbar:suggest-from-gaps --min-occurrences=3')
    ->weeklyOn(1, '04:00')
    ->withoutOverlapping()
    ->runInBackground();

// Hourly delta sync for OAuth sources (Notion pages, Google Docs).
// Website crawls run weekly via pitchbar:refresh-stale-sources; this hits
// the integrations users expect to update much faster.
Schedule::command('pitchbar:sync-oauth-sources --hours=1 --limit=500')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Live-chat auto-fallback: release "needs human" conversations that
// have been waiting for more than 5 minutes and resume the bot with
// a "we'll follow up" message. Without this, an after-hours visitor
// would sit on a holding bubble indefinitely if no operator joins.
Schedule::job(new ReleaseStaleHumanRequestsJob)
    ->everyMinute()
    ->withoutOverlapping();

// Daily admin digest. Inner command short-circuits when
// `app_settings.admin_daily_digest_enabled` is false (default), so the
// scheduler can stay registered without spamming installs that opted
// out. 09:00 UTC chosen as a quiet window across most major regions.
Schedule::command('admin:send-daily-digest')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Turn traces (conversation debugger forensics) expire after
// TURN_TRACE_RETENTION_DAYS (default 14).
Schedule::command('turn-traces:prune')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->runInBackground();

// Sweep expired conversation exports nightly. File + DB row drop after
// expires_at (7 days post-completion).
Schedule::command('conversations:prune-exports')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->runInBackground();

// Prune audit_logs older than 365 days. Operational metadata, not
// compliance evidence — customers with stricter retention need to
// export before this fires. Override via `--days=N` in a project
// schedule if 1 year is wrong for the installation.
Schedule::command('audit:prune --days=365')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();
