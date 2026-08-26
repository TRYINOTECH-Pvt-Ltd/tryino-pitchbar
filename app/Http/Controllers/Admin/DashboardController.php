<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Source;
use App\Support\CurrentWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing dashboard. Cheap, indexed-only queries so the page
 * loads instantly on every login.
 *
 * Each metric ships with:
 *   - a 7-day daily series (for the sparkline)
 *   - the previous 7-day count (for the trend %)
 */
class DashboardController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(Request $request): Response
    {
        $leadStatus = (string) $request->query('lead_status', 'all');
        $leadSort = (string) $request->query('lead_sort', 'created_desc');
        $leadContact = (string) $request->query('lead_contact', 'all');

        if (! in_array($leadStatus, ['all', 'new', 'qualified', 'contacted', 'won', 'lost'], true)) {
            $leadStatus = 'all';
        }

        if (! in_array($leadSort, ['created_desc', 'created_asc', 'name_asc', 'name_desc'], true)) {
            $leadSort = 'created_desc';
        }

        if (! in_array($leadContact, ['all', 'with_email', 'without_email'], true)) {
            $leadContact = 'all';
        }

        $workspace = $this->current->get();
        if ($workspace === null) {
            return Inertia::render('dashboard', [
                'stats' => null,
                'window' => null,
                'chart' => null,
                'reports' => null,
                'filters' => [
                    'lead_status' => $leadStatus,
                    'lead_sort' => $leadSort,
                    'lead_contact' => $leadContact,
                ],
            ]);
        }

        $agentIds = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('id');

        $now = now();
        $sevenDaysAgo = $now->copy()->subDays(7);
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $fourteenDaysAgo = $now->copy()->subDays(14);
        $chartStart = $now->copy()->subDays(29)->startOfDay();

        $conversations = fn () => Conversation::query()
            ->whereIn('agent_id', $agentIds)
            ->where('is_playground', false);
        $leads = fn () => Lead::query()
            ->whereIn('agent_id', $agentIds);
        $sources = fn () => Source::query()
            ->whereIn('agent_id', $agentIds);
        $messages = fn () => Message::query()
            ->whereHas('conversation', fn ($query) => $query
                ->whereIn('agent_id', $agentIds)
                ->where('is_playground', false));

        $convoSeries = $this->daily(
            $conversations(),
            'started_at',
            $sevenDaysAgo,
        );

        $leadSeries = $this->daily(
            $leads(),
            'created_at',
            $sevenDaysAgo,
        );

        $messageSeries = $this->daily(
            $messages(),
            'created_at',
            $sevenDaysAgo,
        );

        $convoChartSeries = $this->dailySpan(
            $conversations(),
            'started_at',
            $chartStart,
            30,
        );

        $leadChartSeries = $this->dailySpan(
            $leads(),
            'created_at',
            $chartStart,
            30,
        );

        $messageChartSeries = $this->dailySpan(
            $messages(),
            'created_at',
            $chartStart,
            30,
        );

        $conversationsLast7 = array_sum($convoSeries);
        $leadsLast7 = array_sum($leadSeries);
        $messagesLast7 = array_sum($messageSeries);
        $conversationsLast30 = array_sum($convoChartSeries);
        $leadsLast30 = array_sum($leadChartSeries);
        $messagesLast30 = array_sum($messageChartSeries);

        $conversationsPrevious7 = (int) $conversations()
            ->whereBetween('started_at', [$fourteenDaysAgo, $sevenDaysAgo])
            ->count();
        $leadsPrevious7 = (int) $leads()
            ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
            ->count();
        $messagesPrevious7 = (int) $messages()
            ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
            ->count();

        $leadStatusCounts = $leads()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $sourceTotal = (int) $sources()->count();
        $sourceIndexed = (int) $sources()->where('status', 'indexed')->count();
        $sourceInProgress = (int) $sources()->whereIn('status', ['pending', 'crawling'])->count();
        $sourceFailed = (int) $sources()->where('status', 'failed')->count();

        $conversionSeries = $this->ratioSeries($leadSeries, $convoSeries);
        $engagementSeries = $this->ratioSeries(
            $messageSeries,
            $convoSeries,
            precision: 1,
            multiplier: 1,
        );

        $topAgentConversationRows = $conversations()
            ->where('started_at', '>=', $chartStart)
            ->selectRaw('agent_id, COUNT(*) as conversations, MAX(started_at) as last_activity_at')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $topAgentLeadCounts = $leads()
            ->where('created_at', '>=', $chartStart)
            ->selectRaw('agent_id, COUNT(*) as leads')
            ->groupBy('agent_id')
            ->pluck('leads', 'agent_id');

        $topAgents = Agent::query()
            ->whereIn('id', $agentIds)
            ->get(['id', 'name', 'is_published'])
            ->map(function (Agent $agent) use ($topAgentConversationRows, $topAgentLeadCounts) {
                $conversationRow = $topAgentConversationRows->get($agent->id);
                $conversationCount = (int) ($conversationRow?->conversations ?? 0);
                $leadCount = (int) ($topAgentLeadCounts[$agent->id] ?? 0);

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'is_published' => (bool) $agent->is_published,
                    'conversations' => $conversationCount,
                    'leads' => $leadCount,
                    'last_activity_at' => $conversationRow?->last_activity_at === null
                        ? null
                        : (string) $conversationRow->last_activity_at,
                ];
            })
            ->filter(fn (array $agent) => $agent['conversations'] > 0 || $agent['leads'] > 0)
            ->sort(function (array $left, array $right) {
                if ($left['conversations'] !== $right['conversations']) {
                    return $right['conversations'] <=> $left['conversations'];
                }

                if ($left['leads'] !== $right['leads']) {
                    return $right['leads'] <=> $left['leads'];
                }

                return strcmp($left['name'], $right['name']);
            })
            ->take(4)
            ->values();

        $recentLeadsQuery = $leads()
            ->with(['agent:id,name']);

        if ($leadStatus !== 'all') {
            $recentLeadsQuery->where('status', $leadStatus);
        }

        if ($leadContact === 'with_email') {
            $recentLeadsQuery->whereNotNull('email')->where('email', '!=', '');
        }

        if ($leadContact === 'without_email') {
            $recentLeadsQuery->where(function ($where) {
                $where->whereNull('email')->orWhere('email', '');
            });
        }

        match ($leadSort) {
            'created_asc' => $recentLeadsQuery->oldest('created_at'),
            'name_asc' => $recentLeadsQuery->orderByRaw('coalesce(name, email) asc'),
            'name_desc' => $recentLeadsQuery->orderByRaw('coalesce(name, email) desc'),
            default => $recentLeadsQuery->latest('created_at'),
        };

        $stats = [
            'conversations' => [
                'total' => $conversations()->count(),
                'last_7d' => $conversationsLast7,
                'previous_7d' => $conversationsPrevious7,
                'last_30d' => $conversationsLast30,
                'series_7d' => array_values($convoSeries),
            ],
            'messages' => [
                'total' => $messages()->count(),
                'last_7d' => $messagesLast7,
                'previous_7d' => $messagesPrevious7,
                'last_30d' => $messagesLast30,
                'series_7d' => array_values($messageSeries),
            ],
            'leads' => [
                'total' => $leads()->count(),
                'last_7d' => $leadsLast7,
                'previous_7d' => $leadsPrevious7,
                'last_30d' => $leadsLast30,
                'series_7d' => array_values($leadSeries),
            ],
            'sources' => [
                'indexed' => $sourceIndexed,
                'in_progress' => $sourceInProgress,
                'failed' => $sourceFailed,
            ],
            'agents' => [
                'total' => $agentIds->count(),
                'published' => Agent::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('is_published', true)
                    ->count(),
                'list' => Agent::query()
                    ->where('workspace_id', $workspace->id)
                    ->latest('updated_at')
                    ->limit(5)
                    ->get(['id', 'name', 'is_published', 'updated_at'])
                    ->map(fn (Agent $a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'is_published' => (bool) $a->is_published,
                        'updated_at' => $a->updated_at?->toIso8601String(),
                    ]),
            ],
            'recent_leads' => (function () use ($recentLeadsQuery, $sevenDaysAgo) {
                $rows = $recentLeadsQuery
                    ->limit(5)
                    ->with('conversation:id,visitor_id')
                    ->get(['id', 'agent_id', 'conversation_id', 'email', 'name', 'status', 'created_at']);

                // Per-lead activity: count conversations from this
                // lead's visitor over the last 7 days. Previously the
                // dashboard piped `stats.conversations.last_7d` (a
                // workspace-wide number) into each row, so every lead
                // displayed the same "65 conversations this week" copy
                // even when the lead's visitor had a single conversation.
                $visitorIds = $rows->pluck('conversation.visitor_id')->filter()->unique();
                $countsByVisitor = $visitorIds->isEmpty()
                    ? collect()
                    : Conversation::query()
                        ->whereIn('visitor_id', $visitorIds)
                        ->where('created_at', '>=', $sevenDaysAgo)
                        ->selectRaw('visitor_id, count(*) as total')
                        ->groupBy('visitor_id')
                        ->pluck('total', 'visitor_id');

                return $rows->map(fn (Lead $l) => [
                    'id' => $l->id,
                    'email' => $l->email,
                    'name' => $l->name,
                    'status' => $l->status,
                    'created_at' => $l->created_at?->toIso8601String(),
                    'conversations_last_7d' => (int) ($countsByVisitor[$l->conversation?->visitor_id] ?? 0),
                    'agent' => $l->agent === null ? null : [
                        'id' => $l->agent->id,
                        'name' => $l->agent->name,
                    ],
                ]);
            })(),
        ];

        return Inertia::render('dashboard', [
            'stats' => $stats,
            'window' => [
                'from' => $chartStart->toIso8601String(),
                'to' => $now->toIso8601String(),
            ],
            'chart' => [
                'days' => array_keys($convoChartSeries),
                'conversations' => array_values($convoChartSeries),
                'leads' => array_values($leadChartSeries),
            ],
            'reports' => [
                'conversion' => [
                    'value' => $this->ratio($leadsLast7, $conversationsLast7),
                    'previous_value' => $this->ratio($leadsPrevious7, $conversationsPrevious7),
                    'last_30d' => $this->ratio($leadsLast30, $conversationsLast30),
                    'series_7d' => array_values($conversionSeries),
                ],
                'engagement' => [
                    'value' => $this->ratio(
                        $messagesLast7,
                        $conversationsLast7,
                        precision: 1,
                        multiplier: 1,
                    ),
                    'previous_value' => $this->ratio(
                        $messagesPrevious7,
                        $conversationsPrevious7,
                        precision: 1,
                        multiplier: 1,
                    ),
                    'last_30d' => $this->ratio(
                        $messagesLast30,
                        $conversationsLast30,
                        precision: 1,
                        multiplier: 1,
                    ),
                    'series_7d' => array_values($engagementSeries),
                ],
                'pipeline' => [
                    'total' => $stats['leads']['total'],
                    'statuses' => collect([
                        'new' => 'New',
                        'qualified' => 'Qualified',
                        'contacted' => 'Contacted',
                        'won' => 'Won',
                        'lost' => 'Lost',
                    ])->map(fn (string $label, string $status) => [
                        'key' => $status,
                        'label' => $label,
                        'value' => (int) ($leadStatusCounts[$status] ?? 0),
                        'share' => $this->ratio(
                            (int) ($leadStatusCounts[$status] ?? 0),
                            (int) $stats['leads']['total'],
                            precision: 0,
                        ),
                    ])->values(),
                ],
                'source_health' => [
                    'total' => $sourceTotal,
                    'indexed' => $sourceIndexed,
                    'in_progress' => $sourceInProgress,
                    'failed' => $sourceFailed,
                    'indexed_rate' => $this->ratio($sourceIndexed, $sourceTotal, precision: 0),
                ],
                'top_agents' => $topAgents,
            ],
            'filters' => [
                'lead_status' => $leadStatus,
                'lead_sort' => $leadSort,
                'lead_contact' => $leadContact,
            ],
        ]);
    }

    /**
     * Bucket rows by calendar day over the last 7 days. Returns a [day => count]
     * map covering every day even when zero (so the sparkline always has 7 points).
     *
     * @return array<string, int>
     */
    private function daily($query, string $column, CarbonInterface $since): array
    {
        return $this->dailySpan($query, $column, $since, 7);
    }

    /**
     * @return array<string, int>
     */
    private function dailySpan($query, string $column, CarbonInterface $since, int $days): array
    {
        // DATE(column) is portable across MySQL, Postgres, and SQLite —
        // returns the YYYY-MM-DD date part. (We previously dispatched
        // by driver and used to_char on non-SQLite, which crashed on
        // MySQL because to_char is Postgres-only.)
        $rows = $query
            ->where($column, '>=', $since)
            ->selectRaw("DATE({$column}) as day, count(*) as c")
            ->groupBy('day')
            ->pluck('c', 'day');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('Y-m-d');
            $series[$day] = (int) ($rows[$day] ?? 0);
        }

        return $series;
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
