<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\ContentGap;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AnalyticsController
{
    public function __construct(private CurrentWorkspace $current) {}

    public function overview(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        $now = CarbonImmutable::now();
        $windowStart = $now->subDays(29)->startOfDay();
        $priorStart = $windowStart->subDays(30);
        $priorEnd = $windowStart->subSecond();
        $degraded = false;

        // Defensive aggregation: each query block is wrapped in a
        // try/catch so a single broken column on a stale deploy can't
        // 500 the entire page. Buyer hit a hard 500 on /app/analytics
        // — the page now degrades gracefully (zeros + a banner)
        // instead of returning a generic Whoops! page. The real error
        // still lands in laravel.log so the operator can debug.
        $conversationsByDay = $this->safeDailyCount(
            fn () => Conversation::query()->where('is_playground', false),
            'started_at',
            $windowStart,
            $now,
            $degraded,
        );
        $messagesByDay = $this->safeDailyCount(
            fn () => Message::query()->whereHas('conversation', fn ($query) => $query->where('is_playground', false)),
            'created_at',
            $windowStart,
            $now,
            $degraded,
        );
        $leadsByDay = $this->safeDailyCount(
            fn () => Lead::query(),
            'created_at',
            $windowStart,
            $now,
            $degraded,
        );

        $conversationPrior = $this->safeCount(
            fn () => (int) Conversation::query()
                ->where('is_playground', false)
                ->whereBetween('started_at', [$priorStart, $priorEnd])
                ->count(),
            $degraded,
        );
        $messagePrior = $this->safeCount(
            fn () => (int) Message::query()
                ->whereHas('conversation', fn ($query) => $query->where('is_playground', false))
                ->whereBetween('created_at', [$priorStart, $priorEnd])
                ->count(),
            $degraded,
        );
        $leadPrior = $this->safeCount(
            fn () => (int) Lead::query()
                ->whereBetween('created_at', [$priorStart, $priorEnd])
                ->count(),
            $degraded,
        );

        $conversations = array_sum($conversationsByDay);
        $messages = array_sum($messagesByDay);
        $leads = array_sum($leadsByDay);

        $conversionSeries = $this->ratioSeries($leadsByDay, $conversationsByDay);
        $engagementSeries = $this->ratioSeries(
            $messagesByDay,
            $conversationsByDay,
            precision: 2,
            multiplier: 1,
        );

        $agents = $this->safeBlock(static function () use ($windowStart, $now) {
            $agentIds = Agent::query()->select('id');

            $conversationCountsByAgent = Conversation::query()
                ->where('is_playground', false)
                ->whereBetween('started_at', [$windowStart, $now])
                ->selectRaw('agent_id, COUNT(*) as conversations')
                ->groupBy('agent_id')
                ->pluck('conversations', 'agent_id');
            $leadCountsByAgent = Lead::query()
                ->whereBetween('created_at', [$windowStart, $now])
                ->selectRaw('agent_id, COUNT(*) as leads')
                ->groupBy('agent_id')
                ->pluck('leads', 'agent_id');
            $messageCountsByAgent = Message::query()
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.is_playground', false)
                ->whereIn('conversations.agent_id', $agentIds)
                ->whereBetween('messages.created_at', [$windowStart, $now])
                ->selectRaw('conversations.agent_id as agent_id, COUNT(messages.id) as messages')
                ->groupBy('conversations.agent_id')
                ->pluck('messages', 'agent_id');
            $lastSeenByAgent = Conversation::query()
                ->where('is_playground', false)
                ->whereBetween('started_at', [$windowStart, $now])
                ->selectRaw('agent_id, MAX(started_at) as last_seen_at')
                ->groupBy('agent_id')
                ->pluck('last_seen_at', 'agent_id');

            return Agent::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_published'])
                ->map(function (Agent $agent) use ($conversationCountsByAgent, $leadCountsByAgent, $messageCountsByAgent, $lastSeenByAgent) {
                    $conversationCount = (int) ($conversationCountsByAgent[$agent->id] ?? 0);
                    $leadCount = (int) ($leadCountsByAgent[$agent->id] ?? 0);
                    $messageCount = (int) ($messageCountsByAgent[$agent->id] ?? 0);
                    $lastSeenAt = $lastSeenByAgent[$agent->id] ?? null;

                    return [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'is_published' => $agent->is_published,
                        'conversations' => $conversationCount,
                        'messages' => $messageCount,
                        'leads' => $leadCount,
                        'conversion_rate' => static::staticRatio($leadCount, $conversationCount),
                        'last_seen_at' => $lastSeenAt === null
                            ? null
                            : CarbonImmutable::parse((string) $lastSeenAt)->toIso8601String(),
                    ];
                })
                ->filter(fn (array $agent) => $agent['conversations'] > 0 || $agent['messages'] > 0 || $agent['leads'] > 0)
                ->sort(function (array $left, array $right) {
                    return [$right['conversations'], $right['leads'], $left['name']]
                        <=> [$left['conversations'], $left['leads'], $right['name']];
                })
                ->take(6)
                ->values()
                ->all();
        }, [], $degraded, 'analytics.agents_block_failed');

        $pages = $this->safeBlock(static function () use ($windowStart, $now) {
            $pageLabelSql = "COALESCE(NULLIF(page_url, ''), '(no page url)')";
            $pageLeadLabelSql = "COALESCE(NULLIF(conversations.page_url, ''), '(no page url)')";

            $pageConversationRows = Conversation::query()
                ->where('is_playground', false)
                ->whereBetween('started_at', [$windowStart, $now])
                ->selectRaw("{$pageLabelSql} as page_url, COUNT(*) as conversations, MAX(started_at) as last_seen_at")
                ->groupByRaw($pageLabelSql)
                ->orderByDesc('conversations')
                ->limit(6)
                ->get();
            $pageLeadCounts = Lead::query()
                ->join('conversations', 'conversations.id', '=', 'leads.conversation_id')
                ->whereBetween('leads.created_at', [$windowStart, $now])
                ->selectRaw("{$pageLeadLabelSql} as page_url, COUNT(leads.id) as leads")
                ->groupByRaw($pageLeadLabelSql)
                ->pluck('leads', 'page_url');

            return $pageConversationRows
                ->map(fn ($row) => [
                    'page_url' => (string) $row->page_url,
                    'conversations' => (int) $row->conversations,
                    'leads' => (int) ($pageLeadCounts[$row->page_url] ?? 0),
                    'conversion_rate' => static::staticRatio(
                        (int) ($pageLeadCounts[$row->page_url] ?? 0),
                        (int) $row->conversations,
                    ),
                    'last_seen_at' => $row->last_seen_at === null
                        ? null
                        : CarbonImmutable::parse((string) $row->last_seen_at)->toIso8601String(),
                ])
                ->values()
                ->all();
        }, [], $degraded, 'analytics.pages_block_failed');

        $openGapCount = $this->safeCount(
            fn () => (int) ContentGap::query()
                ->where('status', 'open')
                ->count(),
            $degraded,
        );

        return Inertia::render('app/analytics/index', [
            'window' => [
                'from' => $windowStart->toIso8601String(),
                'to' => $now->toIso8601String(),
                'days' => array_keys($conversationsByDay),
            ],
            'metrics' => [
                'conversations' => [
                    'value' => $conversations,
                    'prior_value' => $conversationPrior,
                    'series' => array_values($conversationsByDay),
                ],
                'messages' => [
                    'value' => $messages,
                    'prior_value' => $messagePrior,
                    'series' => array_values($messagesByDay),
                ],
                'leads' => [
                    'value' => $leads,
                    'prior_value' => $leadPrior,
                    'series' => array_values($leadsByDay),
                ],
                'conversion_rate' => [
                    'value' => $this->ratio($leads, $conversations),
                    'prior_value' => $this->ratio($leadPrior, $conversationPrior),
                    'series' => array_values($conversionSeries),
                ],
                'engagement' => [
                    'value' => $this->ratio($messages, $conversations, precision: 2, multiplier: 1),
                    'prior_value' => $this->ratio($messagePrior, $conversationPrior, precision: 2, multiplier: 1),
                    'series' => array_values($engagementSeries),
                ],
            ],
            'chart' => [
                'days' => array_keys($conversationsByDay),
                'conversations' => array_values($conversationsByDay),
                'leads' => array_values($leadsByDay),
            ],
            'summary' => [
                'active_agents' => $this->safeCount(
                    fn () => (int) Conversation::query()
                        ->where('is_playground', false)
                        ->whereBetween('started_at', [$windowStart, $now])
                        ->distinct()
                        ->count('agent_id'),
                    $degraded,
                ),
                'published_agents' => $this->safeCount(
                    fn () => (int) Agent::query()
                        ->where('is_published', true)
                        ->count(),
                    $degraded,
                ),
                'pages_tracked' => $this->safeCount(
                    fn () => (int) Conversation::query()
                        ->where('is_playground', false)
                        ->whereBetween('started_at', [$windowStart, $now])
                        ->whereNotNull('page_url')
                        ->where('page_url', '!=', '')
                        ->distinct()
                        ->count('page_url'),
                    $degraded,
                ),
                'open_gaps' => $openGapCount,
            ],
            'agents' => $agents,
            'pages' => $pages,
            'content_gaps' => [
                'open_count' => $openGapCount,
                'top' => $this->safeBlock(
                    static fn () => ContentGap::query()
                        ->where('status', 'open')
                        ->orderByDesc('occurrences')
                        ->orderByDesc('last_seen_at')
                        ->limit(4)
                        ->get(['id', 'question', 'occurrences', 'last_seen_at'])
                        ->map(fn (ContentGap $gap) => [
                            'id' => $gap->id,
                            'question' => $gap->question,
                            'occurrences' => $gap->occurrences,
                            'last_seen_at' => $gap->last_seen_at?->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                    [],
                    $degraded,
                    'analytics.gaps_top_failed',
                ),
            ],
            'csat' => $this->csatPayload($now, $degraded),
            'degraded' => $degraded,
        ]);
    }

    /**
     * Build a 12-week CSAT trend (good / total) plus a top-N list of
     * low-rated conversations (satisfaction=bad, recent) so the admin
     * page can render a "is my bot improving?" panel with deep links
     * to the painful conversations.
     *
     * @return array{
     *     series: array<int, array{week: string, total: int, good: int, score: float|null}>,
     *     latest_score: float|null,
     *     total_rated: int,
     *     low_rated: array<int, array<string, mixed>>
     * }
     */
    private function csatPayload(CarbonImmutable $now, bool &$degraded): array
    {
        $start12w = $now->subWeeks(11)->startOfWeek();

        $series = $this->safeBlock(
            function () use ($start12w, $now): array {
                $rows = Conversation::query()
                    ->where('is_playground', false)
                    ->whereNotNull('satisfaction')
                    ->whereBetween('satisfaction_at', [$start12w, $now])
                    ->get(['satisfaction', 'satisfaction_at'])
                    ->groupBy(fn ($r) => CarbonImmutable::parse($r->satisfaction_at)->startOfWeek()->toDateString());

                $weeks = [];
                $cursor = $start12w;
                while ($cursor <= $now) {
                    $key = $cursor->toDateString();
                    $bucket = $rows[$key] ?? collect();
                    // SatisfactionController writes the widget's
                    // 'positive'/'negative' value verbatim; this
                    // reader counted 'good' for a while which is why
                    // CSAT pinned at 0% even when visitors rated up.
                    $good = $bucket->whereIn('satisfaction', ['positive', 'good'])->count();
                    $total = $bucket->count();
                    $weeks[] = [
                        'week' => $key,
                        'total' => $total,
                        'good' => $good,
                        'score' => $total > 0 ? round($good / $total * 100, 1) : null,
                    ];
                    $cursor = $cursor->addWeek();
                }

                return $weeks;
            },
            [],
            $degraded,
            'analytics.csat_series_failed',
        );

        $latestScore = collect($series)->reverse()->firstWhere(fn ($w) => $w['score'] !== null)['score'] ?? null;
        $totalRated = array_sum(array_column($series, 'total'));

        $lowRated = $this->safeBlock(
            static fn () => Conversation::query()
                ->where('is_playground', false)
                ->whereIn('satisfaction', ['negative', 'bad'])
                ->where('satisfaction_at', '>=', now()->subDays(30))
                ->orderByDesc('satisfaction_at')
                ->limit(10)
                ->get(['id', 'satisfaction_at', 'satisfaction_comment', 'page_url', 'agent_id'])
                ->map(fn (Conversation $c) => [
                    'id' => $c->id,
                    'agent_id' => $c->agent_id,
                    'satisfaction_at' => $c->satisfaction_at?->toIso8601String(),
                    'comment' => $c->satisfaction_comment,
                    'page_url' => $c->page_url,
                ])
                ->values()
                ->all(),
            [],
            $degraded,
            'analytics.csat_low_rated_failed',
        );

        return [
            'series' => $series,
            'latest_score' => $latestScore,
            'total_rated' => (int) $totalRated,
            'low_rated' => $lowRated,
        ];
    }

    public function contentGaps(Request $request): Response
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        $filters = $request->validate([
            'agent_id' => ['nullable', 'string', 'max:36'],
            'status' => ['nullable', 'in:open,answered,ignored'],
            'window' => ['nullable', 'in:7,30,90,all'],
        ]);

        $agents = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $agentIds = $agents->pluck('id');

        $query = ContentGap::query()
            ->withoutGlobalScopes()
            ->whereIn('agent_id', $agentIds);

        $status = $filters['status'] ?? 'open';
        $query->where('status', $status);

        if (! empty($filters['agent_id']) && $agentIds->contains($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        $window = $filters['window'] ?? '30';
        if ($window !== 'all') {
            $days = (int) $window;
            $query->where('last_seen_at', '>=', now()->subDays($days));
        }

        $gaps = $query
            ->orderByDesc('occurrences')
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->map(fn (ContentGap $g) => [
                'id' => $g->id,
                'agent_id' => $g->agent_id,
                'question' => $g->question,
                'occurrences' => $g->occurrences,
                'last_seen_at' => $g->last_seen_at?->toIso8601String(),
                'status' => $g->status,
            ]);

        return Inertia::render('app/analytics/content-gaps', [
            'gaps' => $gaps,
            'agents' => $agents,
            'filters' => [
                'agent_id' => $filters['agent_id'] ?? '',
                'status' => $status,
                'window' => $window,
            ],
        ]);
    }

    public function resolveGap(Request $request, ContentGap $contentGap): RedirectResponse
    {
        $workspace = $this->current->get();
        abort_if($workspace === null, 404);

        $agent = Agent::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $contentGap->agent_id)
            ->first();

        abort_if($agent === null, 404);
        $request->user()->can('update', $agent) || abort(403);

        $contentGap->forceFill([
            'status' => $request->input('action') === 'ignore' ? 'ignored' : 'answered',
        ])->save();

        return back()->with('success', 'Gap marked '.$contentGap->status.'.');
    }

    /**
     * Run an aggregate block; on any DB-shape mismatch (stale migration,
     * missing column, driver-specific SQL surprise), log it, flip the
     * shared $degraded flag, and return the supplied fallback so the
     * Inertia render still succeeds with a partial payload.
     *
     * @template T
     *
     * @param  callable():T  $producer
     * @param  T  $fallback
     * @return T
     */
    private function safeBlock(callable $producer, mixed $fallback, bool &$degraded, string $tag): mixed
    {
        try {
            return $producer();
        } catch (Throwable $e) {
            $degraded = true;
            Log::warning($tag, [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    private function safeCount(callable $producer, bool &$degraded): int
    {
        return (int) $this->safeBlock($producer, 0, $degraded, 'analytics.count_failed');
    }

    /**
     * @return array<string, int>
     */
    private function safeDailyCount(
        callable $queryProducer,
        string $column,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool &$degraded,
    ): array {
        return $this->safeBlock(
            function () use ($queryProducer, $column, $from, $to) {
                return $this->dailyCount($queryProducer(), $column, $from, $to);
            },
            $this->emptyDailySeries($from, $to),
            $degraded,
            'analytics.daily_count_failed',
        );
    }

    /**
     * @return array<string, int>
     */
    private function emptyDailySeries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $out[$cursor->format('Y-m-d')] = 0;
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function dailyCount(
        Builder $query,
        string $column,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
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
        return self::staticRatio($numerator, $denominator, $precision, $multiplier);
    }

    public static function staticRatio(
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
