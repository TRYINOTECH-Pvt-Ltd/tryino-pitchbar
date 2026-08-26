<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Models\WidgetEvent;
use App\Services\Widget\WidgetEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin "what's breaking?" dashboard — the error counterpart to the
 * hot-path latency monitor. Reads the durable `widget_events` sink and renders
 * a filterable feed + at-a-glance counts + a plain-English verdict so an
 * operator can triage widget failures (stream errors, provider outages,
 * client-reported freezes) without grepping laravel.log.
 *
 * Platform-wide and cross-tenant by design (see WidgetEvent's tenancy note);
 * every route is gated behind the `super_admin` middleware.
 */
class WidgetMonitorController
{
    private const RECENT_LIMIT = 200;

    /** Filterable types surfaced in the UI, in display order. */
    private const TYPES = [
        WidgetEventRecorder::TYPE_PROVIDER_DOWN,
        WidgetEventRecorder::TYPE_STREAM_FAILED,
        WidgetEventRecorder::TYPE_PROVIDER_FAILOVER,
        WidgetEventRecorder::TYPE_CLIENT_STALLED,
        WidgetEventRecorder::TYPE_TOOL_LOOP_TIMEOUT,
        WidgetEventRecorder::TYPE_RETRIEVAL_FAILED,
    ];

    public function index(Request $request): Response
    {
        $window = $this->stringQuery($request, 'window');
        $window = in_array($window, ['24h', '7d', '30d'], true) ? $window : '7d';
        $since = match ($window) {
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };

        $type = $this->stringQuery($request, 'type');
        $severity = $this->stringQuery($request, 'severity');
        $status = $this->stringQuery($request, 'status');
        $status = in_array($status, ['open', 'resolved', 'all'], true) ? $status : 'all';

        $query = WidgetEvent::query()->where('occurred_at', '>=', $since);
        if (in_array($type, self::TYPES, true)) {
            $query->where('type', $type);
        }
        if (in_array($severity, [WidgetEvent::SEVERITY_ERROR, WidgetEvent::SEVERITY_WARNING, WidgetEvent::SEVERITY_INFO], true)) {
            $query->where('severity', $severity);
        }
        if ($status === 'open') {
            $query->whereNull('resolved_at');
        } elseif ($status === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        $events = $query->orderByDesc('occurred_at')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (WidgetEvent $e): array => [
                'id' => $e->id,
                'type' => $e->type,
                'severity' => $e->severity,
                'provider' => $e->provider,
                'message' => $e->message,
                'context' => $e->context,
                'agent_id' => $e->agent_id,
                'conversation_id' => $e->conversation_id,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'resolved_at' => $e->resolved_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/widget-monitor', [
            'events' => $events,
            'summary' => $this->summary(),
            'verdict' => $this->verdict(),
            'types' => self::TYPES,
            'filters' => [
                'type' => in_array($type, self::TYPES, true) ? $type : '',
                'severity' => $severity,
                'status' => $status,
                'window' => $window,
            ],
        ]);
    }

    /**
     * Toggle an event's resolved state (triage workflow). Idempotent per
     * click: resolving sets the timestamp + actor; clicking again re-opens.
     */
    public function resolve(Request $request, WidgetEvent $widgetEvent): RedirectResponse
    {
        $reopen = $widgetEvent->resolved_at !== null;

        $widgetEvent->forceFill([
            'resolved_at' => $reopen ? null : now(),
            'resolved_by' => $reopen ? null : $request->user()?->id,
        ])->save();

        return back();
    }

    /**
     * At-a-glance counts. by_type is over the last 24h; open is the standing
     * backlog of unresolved error/warning events; down_last_hour drives the
     * "active outage" banner.
     *
     * @return array{total_24h: int, by_type: array<string, int>, open: int, down_last_hour: int}
     */
    private function summary(): array
    {
        $byType = WidgetEvent::query()
            ->where('occurred_at', '>=', now()->subDay())
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($n): int => (int) $n)
            ->all();

        $open = WidgetEvent::query()
            ->whereNull('resolved_at')
            ->whereIn('severity', [WidgetEvent::SEVERITY_ERROR, WidgetEvent::SEVERITY_WARNING])
            ->where('occurred_at', '>=', now()->subDays(7))
            ->count();

        $downLastHour = WidgetEvent::query()
            ->where('type', WidgetEventRecorder::TYPE_PROVIDER_DOWN)
            ->where('occurred_at', '>=', now()->subHour())
            ->count();

        return [
            'total_24h' => array_sum($byType),
            'by_type' => $byType,
            'open' => $open,
            'down_last_hour' => $downLastHour,
        ];
    }

    /**
     * Plain-English diagnosis, prioritised most-urgent first. Operator-facing
     * (not translated) to match the sibling hot-path latency verdict.
     */
    private function verdict(): string
    {
        $byType = WidgetEvent::query()
            ->where('occurred_at', '>=', now()->subDay())
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($n): int => (int) $n)
            ->all();

        $downLastHour = WidgetEvent::query()
            ->where('type', WidgetEventRecorder::TYPE_PROVIDER_DOWN)
            ->where('occurred_at', '>=', now()->subHour())
            ->count();

        $failover = $byType[WidgetEventRecorder::TYPE_PROVIDER_FAILOVER] ?? 0;

        if ($downLastHour > 0) {
            return $failover > 0
                ? "Provider outage in progress: {$downLastHour} turn(s) hit every provider in the last hour. Failover is firing, so visitors are still served by a backup — but fix the primary (credits / status / keys)."
                : "Provider outage with NO failover: {$downLastHour} turn(s) died in the last hour and there is no backup provider to catch them. Configure a second LLM provider so a single outage stops killing chats.";
        }

        $failed = $byType[WidgetEventRecorder::TYPE_STREAM_FAILED] ?? 0;
        if ($failed > 0) {
            return "{$failed} stream failure(s) in the last 24h. Open one to read the exception class and trace the cause.";
        }

        $stalled = $byType[WidgetEventRecorder::TYPE_CLIENT_STALLED] ?? 0;
        if ($stalled >= 3) {
            return "{$stalled} visitor(s) saw the stream freeze in the last 24h (client-reported stalls). Check latency and provider responsiveness on the hot-path monitor.";
        }

        if ($failover > 0) {
            return "Healthy with hiccups: the primary provider stumbled {$failover} time(s) in the last 24h, but failover covered every turn — visitors noticed nothing.";
        }

        return 'All clear — no widget errors in the last 24 hours.';
    }

    private function stringQuery(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
