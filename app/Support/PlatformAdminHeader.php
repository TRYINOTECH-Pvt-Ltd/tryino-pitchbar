<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformAdminHeader
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $checks = [
            $this->failedJobsCheck(),
            $this->stripeCheck(),
            $this->llmCheck(),
            $this->vectorCheck(),
            $this->mailCheck(),
            $this->reverbCheck(),
            $this->cacheCheck(),
        ];

        $healthyCount = count(array_filter($checks, fn (array $check) => $check['ok']));
        $totalChecks = count($checks);
        $score = (int) round(($healthyCount / max($totalChecks, 1)) * 100);

        // Health-check failures (critical / warning). Buyer-reported
        // (Lucian, 2026-05-15): "would be nice if admin top right
        // notifications icon shows other notifications like new users,
        // new subscriptions". Biz events now ride alongside health
        // checks in the same bell. Health failures take priority in
        // the dropdown; biz items fill the rest.
        $healthNotifications = array_values(array_map(
            fn (array $check) => [
                'key' => $check['key'],
                'title' => $check['title'],
                'body' => $check['message'],
                'severity' => $check['severity'],
                'href' => $check['href'],
            ],
            array_filter($checks, fn (array $check) => ! $check['ok']),
        ));

        $businessNotifications = $this->businessEvents();
        $notifications = array_merge($healthNotifications, $businessNotifications);

        return [
            'site_health' => [
                'score' => $score,
                'label' => $this->scoreLabel($score),
                'status' => $this->scoreStatus($score),
                'ok_checks' => $healthyCount,
                'total_checks' => $totalChecks,
                // The badge counts health issues only — a "5 new users"
                // info card shouldn't paint the bell red.
                'issues_count' => count($healthNotifications),
            ],
            'notifications' => [
                // Unread badge = health issues + business events with non-zero counts.
                'unread_count' => count($notifications),
                'items' => $notifications !== []
                    ? $notifications
                    : [[
                        'key' => 'all_clear',
                        'title' => 'All platform checks look healthy',
                        'body' => 'No urgent admin actions are currently waiting in the navbar.',
                        'severity' => 'info',
                        'href' => route('settings.system.index', absolute: false),
                    ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobsCheck(): array
    {
        $count = (int) DB::table('failed_jobs')->count();

        return [
            'key' => 'failed_jobs',
            'title' => $count > 0
                ? $count.' queue '.Str::plural('failure', $count).' '.($count === 1 ? 'needs' : 'need').' attention'
                : 'Queue ledger is clean',
            'message' => $count > 0
                ? 'Open queue failures to retry transient failures or remove poison pills.'
                : 'No queue failures are waiting in the queue ledger.',
            'severity' => 'critical',
            'ok' => $count === 0,
            'href' => route('admin.jobs.failed', absolute: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeCheck(): array
    {
        $configured = (string) (config('cashier.secret') ?: env('STRIPE_SECRET', '')) !== '';

        return [
            'key' => 'stripe',
            'title' => $configured ? 'Stripe billing is connected' : 'Stripe billing is not connected',
            'message' => $configured
                ? 'Subscription billing keys are present.'
                : 'Add a Stripe secret key before using subscription billing.',
            'severity' => 'warning',
            'ok' => $configured,
            'href' => route('settings.system.index', absolute: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function llmCheck(): array
    {
        $provider = (string) config('services.llm.provider', '');
        $cfAccount = (string) config('services.cloudflare.account_id', '');
        $cfToken = (string) config('services.cloudflare.api_token', '');
        $openAiKey = (string) config('services.openai.key', '');
        $openRouterKey = (string) config('services.openrouter.key', '');

        $configured = match (true) {
            $provider === 'cloudflare' || ($provider === '' && $cfAccount !== '' && $cfToken !== '') => true,
            $provider === 'openrouter' && $openRouterKey !== '' => true,
            $openAiKey !== '' => true,
            default => false,
        };

        return [
            'key' => 'llm',
            'title' => $configured ? 'LLM provider is configured' : 'LLM provider is missing',
            'message' => $configured
                ? 'At least one chat provider is ready for the hot path.'
                : 'Configure Cloudflare, OpenAI, or OpenRouter for production chat responses.',
            'severity' => 'warning',
            'ok' => $configured,
            'href' => route('settings.system.index', absolute: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vectorCheck(): array
    {
        $provider = (string) config('services.vector.provider', '');
        $cfAccount = (string) config('services.cloudflare.account_id', '');
        $qdrantUrl = (string) config('services.qdrant.url', '');

        $configured = match (true) {
            $provider === 'cloudflare' || ($provider === '' && $cfAccount !== '') => true,
            $provider === 'qdrant' || ($provider === '' && $qdrantUrl !== '') => true,
            default => false,
        };

        return [
            'key' => 'vector',
            'title' => $configured ? 'Vector search is configured' : 'Vector search is missing',
            'message' => $configured
                ? 'A vector provider is available for retrieval.'
                : 'Configure Cloudflare Vectorize or Qdrant before relying on retrieval.',
            'severity' => 'warning',
            'ok' => $configured,
            'href' => route('settings.system.index', absolute: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mailCheck(): array
    {
        $driver = (string) config('mail.default', '');
        $mailer = (array) config("mail.mailers.{$driver}", []);
        $fromAddress = (string) (config('mail.from.address') ?? '');

        $configured = $driver !== ''
            && $fromAddress !== ''
            && ($driver !== 'smtp' || (string) ($mailer['host'] ?? '') !== '');

        return [
            'key' => 'mail',
            'title' => $configured ? 'Mail delivery is configured' : 'Mail delivery needs attention',
            'message' => $configured
                ? 'Outbound mail has a driver and sender configured.'
                : 'Set the mail driver, sender address, and SMTP host before relying on email.',
            'severity' => 'warning',
            'ok' => $configured,
            'href' => route('settings.system.index', absolute: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reverbCheck(): array
    {
        $configured = env('REVERB_APP_KEY') !== null && env('REVERB_APP_KEY') !== '';

        return [
            'key' => 'reverb',
            'title' => $configured ? 'Realtime transport is configured' : 'Realtime transport is missing',
            'message' => $configured
                ? 'Reverb credentials are present for websocket delivery.'
                : 'Add Reverb credentials so admin and widget realtime features can connect reliably.',
            'severity' => 'warning',
            'ok' => $configured,
            'href' => route('settings.system.index', absolute: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheCheck(): array
    {
        $driver = (string) config('cache.default', '');
        $configured = $driver !== ''
            && ($driver !== 'redis' || (string) env('REDIS_HOST', '') !== '');

        return [
            'key' => 'cache',
            'title' => $configured ? 'Cache layer is available' : 'Cache layer needs attention',
            'message' => $configured
                ? 'The application has a cache driver configured.'
                : 'Configure the cache driver so queue and session flows stay responsive.',
            'severity' => 'warning',
            'ok' => $configured,
            'href' => route('settings.system.index', absolute: false),
        ];
    }

    /**
     * Last-24h business-event notifications. Only surfaces non-zero
     * categories so an idle install shows a clean bell. Cached for
     * 60 seconds — the dashboard already does richer reporting; this
     * lane is for "something happened, you should glance" pings.
     *
     * @return array<int, array<string, mixed>>
     */
    private function businessEvents(): array
    {
        return Cache::remember(
            'platform_admin_header.business_events',
            60,
            function (): array {
                $since = Carbon::now()->subDay();
                $events = [];

                $newUsers = (int) User::query()
                    ->where('created_at', '>=', $since)
                    ->count();
                if ($newUsers > 0) {
                    $events[] = [
                        'key' => 'new_users_24h',
                        'title' => $newUsers.' new '.Str::plural('user', $newUsers).' signed up',
                        'body' => 'In the last 24 hours.',
                        'severity' => 'info',
                        'href' => route('admin.users.index', absolute: false),
                    ];
                }

                // withoutGlobalScopes() — platform-wide super_admin
                // dashboard counts every workspace across every tenant.
                // BelongsToWorkspace would filter to the super_admin's
                // own workspace; this metric is intentionally global.
                $newWorkspaces = (int) Workspace::query()
                    ->withoutGlobalScopes()
                    ->where('created_at', '>=', $since)
                    ->count();
                if ($newWorkspaces > 0) {
                    $events[] = [
                        'key' => 'new_workspaces_24h',
                        'title' => $newWorkspaces.' new '.Str::plural('workspace', $newWorkspaces).' created',
                        'body' => 'In the last 24 hours.',
                        'severity' => 'info',
                        'href' => route('admin.workspaces.index', absolute: false),
                    ];
                }

                // Newly-paid subscriptions — count Workspaces whose
                // plan_id was set in the last 24h AND the plan is paid
                // (price_cents > 0). Direct DB join keeps the query
                // cheap; subscriptions table only has Stripe rows.
                $newSubs = (int) DB::table('workspaces')
                    ->join('plans', 'plans.id', '=', 'workspaces.plan_id')
                    ->where('workspaces.updated_at', '>=', $since)
                    ->where('plans.price_cents', '>', 0)
                    ->count();
                if ($newSubs > 0) {
                    $events[] = [
                        'key' => 'new_subscriptions_24h',
                        'title' => $newSubs.' new paid '.Str::plural('subscription', $newSubs),
                        'body' => 'In the last 24 hours. Open Subscriptions to verify.',
                        'severity' => 'info',
                        'href' => route('admin.subscriptions.index', absolute: false),
                    ];
                }

                // withoutGlobalScopes() — same platform-wide rationale
                // as the workspace count above. Lead uses BelongsToAgent;
                // without scope-strip the count would silently return 0.
                $newLeads = (int) Lead::query()
                    ->withoutGlobalScopes()
                    ->where('created_at', '>=', $since)
                    ->count();
                if ($newLeads > 0) {
                    $events[] = [
                        'key' => 'new_leads_24h',
                        'title' => $newLeads.' new '.Str::plural('lead', $newLeads).' captured platform-wide',
                        'body' => 'In the last 24 hours.',
                        'severity' => 'info',
                        'href' => route('admin.leads.index', absolute: false),
                    ];
                }

                return $events;
            },
        );
    }

    private function scoreLabel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Strong',
            $score >= 70 => 'Stable',
            $score >= 50 => 'Watchlist',
            default => 'Critical',
        };
    }

    private function scoreStatus(int $score): string
    {
        return match (true) {
            $score >= 90 => 'healthy',
            $score >= 70 => 'warning',
            default => 'critical',
        };
    }
}
