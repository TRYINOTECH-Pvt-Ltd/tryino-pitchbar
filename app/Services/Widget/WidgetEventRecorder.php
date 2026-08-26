<?php

namespace App\Services\Widget;

use App\Jobs\Widget\RecordWidgetEventJob;
use App\Models\WidgetEvent;
use Illuminate\Support\Facades\Log;

/**
 * Records widget-reliability failures/anomalies into the `widget_events`
 * sink that powers the super-admin Widget Monitor.
 *
 * Two hard rules, both mirroring HotPathTimer's discipline:
 *
 *  1. **Off the hot path.** `record()` only ever dispatches an async job; it
 *     performs no synchronous DB write. Callers are failure/recovery sites
 *     (a top-level stream catch, a provider failover, a client-reported
 *     stall) — never the first-token success path.
 *
 *  2. **Observability must never break the visitor.** The whole call is
 *     wrapped so a queue/serialization hiccup degrades to a log line instead
 *     of throwing into the stream. Recording a problem must not itself
 *     become the problem.
 *
 * Stateless and Octane-safe: no constructor, no captured request/container.
 */
class WidgetEventRecorder
{
    /**
     * Known event types. Stable slugs — the monitor filters on these and the
     * docs list them, so don't rename without updating both.
     */
    public const TYPE_STREAM_FAILED = 'stream_failed';

    public const TYPE_PROVIDER_FAILOVER = 'provider_failover';

    public const TYPE_PROVIDER_DOWN = 'provider_down';

    public const TYPE_TOOL_LOOP_TIMEOUT = 'tool_loop_timeout';

    public const TYPE_RETRIEVAL_FAILED = 'retrieval_failed';

    public const TYPE_CLIENT_STALLED = 'client_stalled';

    /**
     * Record one event. All identity fields are optional — a failure deep in
     * the LLM layer may only know the provider + exception, while a stream
     * catch knows the conversation. Pass what you have via named args.
     *
     * @param  array<string, mixed>  $context  Structured detail (exception
     *                                         class, fallback that won,
     *                                         elapsed ms, client reason, …).
     */
    public function record(
        string $type,
        string $severity = WidgetEvent::SEVERITY_ERROR,
        ?string $provider = null,
        ?string $message = null,
        array $context = [],
        ?string $workspaceId = null,
        ?string $agentId = null,
        ?string $conversationId = null,
    ): void {
        try {
            RecordWidgetEventJob::dispatch([
                'workspace_id' => $this->cleanUuid($workspaceId),
                'agent_id' => $this->cleanUuid($agentId),
                'conversation_id' => $this->cleanUuid($conversationId),
                'type' => $type,
                'severity' => $severity,
                'provider' => $provider,
                // Hard cap so a giant provider error body can't blow the column.
                'message' => $message !== null ? mb_substr($message, 0, 1000) : null,
                'context' => $context,
                'occurred_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            // Never let telemetry break the path that triggered it.
            Log::warning('widget.event_record_failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalise an id to a non-empty string or null. The hot path passes
     * `(string) ($claims['conversation_id'] ?? '')` which is `''` when
     * absent — store NULL, not an empty string, so the column stays clean.
     */
    private function cleanUuid(?string $id): ?string
    {
        if (! is_string($id)) {
            return null;
        }

        $id = trim($id);

        return $id === '' ? null : $id;
    }
}
