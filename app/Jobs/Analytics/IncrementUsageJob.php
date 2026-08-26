<?php

namespace App\Jobs\Analytics;

use App\Models\Conversation;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Increment a usage_events row only on the FIRST user message of a
 * conversation **within the current billing month**. Idempotent via a
 * Redis lock keyed on (conversation_id, YYYY-MM).
 *
 * Why the month suffix matters: the billing UI shows
 * `count(usage_events WHERE occurred_at >= startOfMonth)`, so the
 * dedupe scope must match. A previous version locked per-conversation
 * for 30 days, which silently swallowed the count for shoppers who
 * returned in a new month on the same conversation cookie. Resetting
 * the lock at the month boundary fixes that without double-counting
 * mid-month re-engagements.
 */
class IncrementUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // $tries=1 because the message-event insert is non-idempotent —
    // retry would double-bill.
    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public string $conversationId)
    {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $conversation = Conversation::query()->withoutWorkspaceScope()->find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        $agent = $conversation->agent()->withoutWorkspaceScope()->first();
        if ($agent === null) {
            return;
        }

        $workspaceId = (string) ($agent->workspace_id ?? '');
        if ($workspaceId === '') {
            // Defensive: a conversation pointed at an agent missing a
            // workspace_id would otherwise insert NULL into
            // usage_events.workspace_id — which never matches the
            // billing query and produces a stuck counter. Skip cleanly
            // + log so the operator can fix the orphan agent.
            Log::warning('usage.increment.skipped_missing_workspace', [
                'conversation_id' => $this->conversationId,
                'agent_id' => $agent->id,
            ]);

            return;
        }

        $now = CarbonImmutable::now();
        $monthKey = $now->format('Y-m');

        // Per-month dedupe: a returning visitor on the same conversation
        // cookie next month counts again (matches what the UI shows).
        // TTL = end-of-month + 7 days slop so a turn that arrives a
        // few seconds before the rollover still wins the lock cleanly.
        $conversationKey = "usage:counted:{$this->conversationId}:{$monthKey}";
        $endOfMonth = $now->endOfMonth()->addDays(7);

        if (Cache::add($conversationKey, true, $endOfMonth)) {
            UsageEvent::create([
                'workspace_id' => $workspaceId,
                'kind' => 'conversation',
                'quantity' => 1,
                'meta' => [
                    'conversation_id' => $conversation->id,
                    'month' => $monthKey,
                ],
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
        }

        UsageEvent::create([
            'workspace_id' => $workspaceId,
            'kind' => 'message',
            'quantity' => 1,
            'meta' => ['conversation_id' => $conversation->id],
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('usage.increment.failed', [
            'conversation_id' => $this->conversationId,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);
    }
}
