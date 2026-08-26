<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Support\QueueHealth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-wide overview shown at /admin. Cheap, indexed-only queries
 * so the page loads instantly on every login.
 *
 * Each stat ships with:
 *   - the current value
 *   - a daily series for the last 30 days (sparkline)
 *   - the previous-30-day total (for trend %)
 *
 * Plus a 30-day chart series for conversations and messages, a "right
 * now" snapshot of active conversations, and a small platform-activity
 * feed seeded from recent agent publishes + workspace creations.
 */
class DashboardController
{
    public function __invoke(): Response
    {
        $now = CarbonImmutable::now();
        $startOfToday = $now->startOfDay();
        $sparkStart = $now->subDays(29)->startOfDay();   // 30-day window
        $priorStart = $now->subDays(59)->startOfDay();
        $priorEnd = $now->subDays(30)->endOfDay();

        // Daily series for the chart + sparklines (conversations + messages).
        $conversationsByDay = $this->dailyCount(
            Conversation::query()->withoutGlobalScopes(),
            'started_at',
            $sparkStart,
            $now,
        );
        $messagesByDay = $this->dailyCount(
            Message::query()->whereHas('conversation', fn ($q) => $q->withoutGlobalScopes()),
            'created_at',
            $sparkStart,
            $now,
        );
        $usersByDay = $this->dailyCount(
            User::query(),
            'created_at',
            $sparkStart,
            $now,
        );
        $workspacesByDay = $this->dailyCount(
            Workspace::query()->withoutGlobalScopes(),
            'created_at',
            $sparkStart,
            $now,
        );
        $agentsByDay = $this->dailyCount(
            Agent::query()->withoutGlobalScopes(),
            'created_at',
            $sparkStart,
            $now,
        );
        $leadsByDay = $this->dailyCount(
            Lead::query()->withoutGlobalScopes(),
            'created_at',
            $sparkStart,
            $now,
        );

        // Prior-period totals for trend %.
        $convPrior = Conversation::query()->withoutGlobalScopes()
            ->whereBetween('started_at', [$priorStart, $priorEnd])->count();
        $msgPrior = Message::query()
            ->whereHas('conversation', fn ($q) => $q->withoutGlobalScopes())
            ->whereBetween('created_at', [$priorStart, $priorEnd])->count();
        $userPrior = User::query()->whereBetween('created_at', [$priorStart, $priorEnd])->count();
        $workspacePrior = Workspace::query()->withoutGlobalScopes()
            ->whereBetween('created_at', [$priorStart, $priorEnd])->count();
        $agentPrior = Agent::query()->withoutGlobalScopes()
            ->whereBetween('created_at', [$priorStart, $priorEnd])->count();
        $leadPrior = Lead::query()->withoutGlobalScopes()
            ->whereBetween('created_at', [$priorStart, $priorEnd])->count();

        $leadCaptureSeries = $this->ratioSeries($leadsByDay, $conversationsByDay);
        $engagementSeries = $this->ratioSeries(
            $messagesByDay,
            $conversationsByDay,
            precision: 2,
            multiplier: 1,
        );

        $usersTotal = (int) User::query()->count();
        $workspacesTotal = (int) Workspace::query()->withoutGlobalScopes()->count();
        $agentsTotal = (int) Agent::query()->withoutGlobalScopes()->count();
        $agentsPublished = (int) Agent::query()->withoutGlobalScopes()->where('is_published', true)->count();
        $leadsTotal = (int) Lead::query()->withoutGlobalScopes()->count();
        $leadsLast30 = array_sum($leadsByDay);
        $convLast30 = array_sum($conversationsByDay);
        $msgLast30 = array_sum($messagesByDay);
        $usersLast30 = array_sum($usersByDay);
        $workspacesLast30 = array_sum($workspacesByDay);
        $agentsLast30 = array_sum($agentsByDay);

        $publishedWorkspaces = (int) Agent::query()->withoutGlobalScopes()
            ->where('is_published', true)
            ->distinct()
            ->count('workspace_id');
        $activePublishedAgents30 = (int) Agent::query()->withoutGlobalScopes()
            ->where('is_published', true)
            ->whereHas('conversations', fn ($query) => $query
                ->withoutGlobalScopes()
                ->whereBetween('started_at', [$sparkStart, $now])
                ->where('is_playground', false))
            ->count();
        $workspacesWithTraffic30 = (int) Agent::query()->withoutGlobalScopes()
            ->whereHas('conversations', fn ($query) => $query
                ->withoutGlobalScopes()
                ->whereBetween('started_at', [$sparkStart, $now])
                ->where('is_playground', false))
            ->distinct()
            ->count('workspace_id');

        // "Right now" — anything started in the last hour and not closed,
        // plus message count since today's UTC midnight.
        $activeConversations = (int) Conversation::query()->withoutGlobalScopes()
            ->where('started_at', '>=', $now->subHour())
            ->whereNull('ended_at')
            ->count();
        $messagesToday = (int) Message::query()
            ->whereHas('conversation', fn ($q) => $q->withoutGlobalScopes())
            ->where('created_at', '>=', $startOfToday)
            ->count();

        return Inertia::render('admin/dashboard', [
            'window' => [
                'from' => $sparkStart->toIso8601String(),
                'to' => $now->toIso8601String(),
                'days' => array_keys($conversationsByDay),
            ],
            // Live queue health — last cron tick, pending + failed
            // job counts, recent tick history. The dashboard poll loop
            // refreshes this every 8s so a buyer can leave the page
            // open while debugging "is the Cloudflare Worker actually
            // running my queue?" without hopping into Settings →
            // System → Cron worker.
            'queueHealth' => QueueHealth::summary(10),
            'metrics' => [
                'users' => [
                    'value' => $usersTotal,
                    'series' => array_values($usersByDay),
                    'prior_30d' => $userPrior,
                    'last_30d' => array_sum($usersByDay),
                ],
                'workspaces' => [
                    'value' => $workspacesTotal,
                    'series' => array_values($workspacesByDay),
                    'prior_30d' => $workspacePrior,
                    'last_30d' => array_sum($workspacesByDay),
                ],
                'agents' => [
                    'value' => $agentsTotal,
                    'series' => array_values($agentsByDay),
                    'prior_30d' => $agentPrior,
                    'last_30d' => array_sum($agentsByDay),
                    'published' => $agentsPublished,
                ],
                'leads' => [
                    'value' => $leadsTotal,
                    'series' => array_values($leadsByDay),
                    'prior_30d' => $leadPrior,
                    'last_30d' => $leadsLast30,
                ],
                'conversations' => [
                    'value' => $convLast30,
                    'series' => array_values($conversationsByDay),
                    'prior_30d' => $convPrior,
                ],
                'messages' => [
                    'value' => $msgLast30,
                    'series' => array_values($messagesByDay),
                    'prior_30d' => $msgPrior,
                ],
            ],
            'chart' => [
                'days' => array_keys($conversationsByDay),
                'conversations' => array_values($conversationsByDay),
                'messages' => array_values($messagesByDay),
            ],
            'reports' => [
                'growth' => [
                    'days' => array_keys($usersByDay),
                    'users' => array_values($usersByDay),
                    'workspaces' => array_values($workspacesByDay),
                    'agents' => array_values($agentsByDay),
                ],
                'funnel' => [
                    [
                        'label' => 'New signups',
                        'value' => $usersLast30,
                        'helper' => 'Created in the last 30 days',
                    ],
                    [
                        'label' => 'Workspaces created',
                        'value' => $workspacesLast30,
                        'helper' => 'New tenant accounts',
                    ],
                    [
                        'label' => 'Agents created',
                        'value' => $agentsLast30,
                        'helper' => 'Fresh bots launched by customers',
                    ],
                    [
                        'label' => 'Leads captured',
                        'value' => $leadsLast30,
                        'helper' => 'Contacts generated in the same window',
                    ],
                ],
                'lead_capture' => [
                    'value' => $this->ratio($leadsLast30, $convLast30),
                    'prior_value' => $this->ratio($leadPrior, $convPrior),
                    'last_7d' => $this->ratio(
                        array_sum(array_slice(array_values($leadsByDay), -7)),
                        array_sum(array_slice(array_values($conversationsByDay), -7)),
                    ),
                    'series' => array_values($leadCaptureSeries),
                    'leads' => $leadsLast30,
                    'conversations' => $convLast30,
                ],
                'engagement' => [
                    'value' => $this->ratio(
                        $msgLast30,
                        $convLast30,
                        precision: 2,
                        multiplier: 1,
                    ),
                    'prior_value' => $this->ratio(
                        $msgPrior,
                        $convPrior,
                        precision: 2,
                        multiplier: 1,
                    ),
                    'last_7d' => $this->ratio(
                        array_sum(array_slice(array_values($messagesByDay), -7)),
                        array_sum(array_slice(array_values($conversationsByDay), -7)),
                        precision: 2,
                        multiplier: 1,
                    ),
                    'series' => array_values($engagementSeries),
                    'messages' => $msgLast30,
                    'conversations' => $convLast30,
                ],
                'deployment' => [
                    'published_agents' => $agentsPublished,
                    'active_published_agents' => $activePublishedAgents30,
                    'inactive_published_agents' => max($agentsPublished - $activePublishedAgents30, 0),
                    'utilization_rate' => $this->ratio(
                        $activePublishedAgents30,
                        $agentsPublished,
                    ),
                    'published_workspaces' => $publishedWorkspaces,
                    'workspaces_with_traffic' => $workspacesWithTraffic30,
                    'workspace_coverage_rate' => $this->ratio(
                        $workspacesWithTraffic30,
                        $workspacesTotal,
                    ),
                    'avg_conversations_per_active_agent' => $this->ratio(
                        $convLast30,
                        $activePublishedAgents30,
                        precision: 1,
                        multiplier: 1,
                    ),
                ],
            ],
            'right_now' => [
                'active_conversations' => $activeConversations,
                'messages_today' => $messagesToday,
                'as_of' => $now->toIso8601String(),
            ],
            'recent_signups' => User::query()
                ->latest()
                ->limit(6)
                ->get(['id', 'name', 'email', 'role', 'created_at']),
            'activity' => $this->activityFeed(),
        ]);
    }

    /**
     * Count rows per day across the given window, returning an
     * 'YYYY-MM-DD' keyed array with a zero-filled entry for every day
     * (so the sparkline doesn't have gaps).
     *
     * @return array<string, int>
     */
    private function dailyCount(
        Builder $query,
        string $column,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        // SQLite + Postgres both understand DATE() — use it for portability.
        $rows = $query
            ->whereBetween($column, [$from, $to])
            ->selectRaw("DATE({$column}) as day, COUNT(*) as c")
            ->groupBy('day')
            ->pluck('c', 'day')
            ->toArray();

        $out = [];
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m-d');
            $out[$key] = (int) ($rows[$key] ?? 0);
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * Synthesize a small platform-activity feed from recent domain events:
     * agent publishes (proxied via updated_at on is_published rows) and
     * fresh workspace creations. Cheap enough to run inline.
     *
     * @return array<int, array{kind: string, title: string, subtitle: string|null, at: string}>
     */
    private function activityFeed(): array
    {
        $items = [];

        $recentAgents = Agent::query()->withoutGlobalScopes()
            ->where('is_published', true)
            ->where('updated_at', '>=', now()->subDays(14))
            ->with('workspace:id,name')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'workspace_id', 'name', 'updated_at']);

        foreach ($recentAgents as $agent) {
            $items[] = [
                'kind' => 'agent.published',
                'title' => "{$agent->name} went live",
                'subtitle' => 'Agent published in '.($agent->workspace?->name ?? 'a workspace'),
                'at' => $agent->updated_at->toIso8601String(),
            ];
        }

        $recentWorkspaces = Workspace::query()->withoutGlobalScopes()
            ->where('created_at', '>=', now()->subDays(14))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'name', 'created_at']);

        foreach ($recentWorkspaces as $ws) {
            $items[] = [
                'kind' => 'workspace.created',
                'title' => "{$ws->name} created",
                'subtitle' => 'Workspace created',
                'at' => $ws->created_at->toIso8601String(),
            ];
        }

        usort($items, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($items, 0, 8);
    }

    /**
     * @param  array<string, int>  $numerator
     * @param  array<string, int>  $denominator
     * @return array<string, float>
     */
    private function ratioSeries(
        array $numerator,
        array $denominator,
        int $precision = 1,
        int $multiplier = 100,
    ): array {
        $series = [];

        foreach ($numerator as $key => $value) {
            $series[$key] = $this->ratio(
                $value,
                (int) ($denominator[$key] ?? 0),
                precision: $precision,
                multiplier: $multiplier,
            );
        }

        return $series;
    }

    private function ratio(
        int|float $numerator,
        int|float $denominator,
        int $precision = 1,
        int $multiplier = 100,
    ): float {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * $multiplier, $precision);
    }
}
