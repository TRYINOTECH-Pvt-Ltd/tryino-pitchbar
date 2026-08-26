<?php

namespace App\Console\Commands\Admin;

use App\Enums\PlatformRole;
use App\Mail\AdminDailyDigest;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send the daily admin digest to every super_admin with an email
 * address. Opt-in via `app_settings.admin_daily_digest_enabled` —
 * disabled by default. Idempotent: re-running on the same day just
 * re-sends the same numbers.
 *
 * Scheduled at 09:00 UTC daily via console.php. Use `--force` to bypass
 * the enabled flag (useful for manual previews) and `--dry-run` to
 * compute the digest without sending mail.
 */
class SendDailyDigestCommand extends Command
{
    protected $signature = 'admin:send-daily-digest
                            {--force : Send even when admin_daily_digest_enabled is false}
                            {--confirm-production : Required when APP_ENV=production AND --force is used to override the opted-out flag}
                            {--dry-run : Compute and print the digest without sending mail}';

    protected $description = 'Email super_admins a 24-hour summary of new users, subscriptions, workspaces, and leads.';

    public function handle(): int
    {
        $settings = AppSetting::singleton();
        $enabled = (bool) ($settings->admin_daily_digest_enabled ?? false);

        // In production, --force still requires --confirm-production so a
        // misconfigured cron can't email super_admins past an opt-out.
        if (! $enabled) {
            if (! $this->option('force')) {
                $this->info('Admin daily digest is disabled (app_settings.admin_daily_digest_enabled = false). Skipping.');

                return self::SUCCESS;
            }
            if (app()->isProduction() && ! $this->option('confirm-production')) {
                $this->error('Refusing to --force the disabled digest in production without --confirm-production.');
                $this->line('Admins opted out via app_settings.admin_daily_digest_enabled = false; force-sending would email every super_admin against that opt-out.');

                return self::FAILURE;
            }
        }

        $end = Carbon::now();
        $start = (clone $end)->subDay();

        $stats = [
            'period_start' => $start->format('Y-m-d H:i').' UTC',
            'period_end' => $end->format('Y-m-d H:i').' UTC',
            'new_users' => (int) User::query()
                ->where('created_at', '>=', $start)
                ->count(),
            // withoutGlobalScopes() — daily digest is a platform-wide
            // summary for super_admins. No request session → no
            // CurrentWorkspace → BelongsToWorkspace would zero every count.
            'new_workspaces' => (int) Workspace::query()
                ->withoutGlobalScopes()
                ->where('created_at', '>=', $start)
                ->count(),
            'new_subscriptions' => (int) DB::table('workspaces')
                ->join('plans', 'plans.id', '=', 'workspaces.plan_id')
                ->where('workspaces.updated_at', '>=', $start)
                ->where('plans.price_cents', '>', 0)
                ->count(),
            'new_leads' => (int) Lead::query()
                ->withoutGlobalScopes()
                ->where('created_at', '>=', $start)
                ->count(),
            'active_conversations' => (int) Conversation::query()
                ->withoutGlobalScopes()
                ->where('started_at', '>=', $end->copy()->subHour())
                ->whereNull('ended_at')
                ->count(),
            'admin_url' => url('/admin'),
        ];

        $this->line('Computed digest:');
        $this->line('  New users: '.$stats['new_users']);
        $this->line('  New workspaces: '.$stats['new_workspaces']);
        $this->line('  New paid subscriptions: '.$stats['new_subscriptions']);
        $this->line('  New leads: '.$stats['new_leads']);
        $this->line('  Active conversations: '.$stats['active_conversations']);

        if ($this->option('dry-run')) {
            $this->info('Dry run — not sending mail.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->where('role', PlatformRole::SuperAdmin)
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        if ($recipients === []) {
            $this->warn('No super_admin users with email. Nothing sent.');

            return self::SUCCESS;
        }

        try {
            Mail::to($recipients)->send(new AdminDailyDigest($stats));
            $this->info('Sent to '.count($recipients).' super_admin(s).');
        } catch (\Throwable $e) {
            // Don't crash the scheduler — log and exit clean. Mail
            // misconfig shouldn't take down the digest cron loop.
            Log::warning('admin.daily_digest_send_failed', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Mail dispatch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
