<?php

namespace App\Jobs\LiveChat;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\HumanRequestedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Fan out HumanRequestedNotification to every operator on the
 * workspace who opted in to live-chat duty. Queued so the visitor's
 * HTTP request never waits on SMTP — buyer reported a 2s+ pause on
 * click before this was async.
 *
 * Audience rule:
 *   - workspace members with an accepted invitation
 *   - users.live_chat_available = true (the operator explicitly opted
 *     in via Profile → Notifications)
 *
 * We notify regardless of presence (last_active_at). The point of this
 * job is precisely to wake up operators who aren't on the inbox tab —
 * presence is only used to decide whether the widget waits 2 minutes
 * or falls back immediately, and that decision lives in
 * RequestHumanController.
 */
class NotifyOperatorsHumanRequestedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * `$tries = 1` (audit 2026-05-16): this job sends emails to every
     * eligible operator. Retrying the WHOLE job after a partial SMTP
     * failure (rate-limited mid-loop, transient network blip) would
     * re-send to operators who already got the first batch — buyer-
     * facing "duplicate alert" annoyance. The HumanRequestedNotification
     * itself is ShouldQueue, so each recipient lands on its own queue
     * row; failures there are isolated per-recipient and surface in
     * failed_jobs. Single-shot at THIS job level keeps the fan-out
     * idempotent across operator addresses.
     */
    public int $tries = 1;

    public function __construct(
        public string $conversationId,
        public string $workspaceId,
    ) {
        $this->onQueue('default');
    }

    public function failed(\Throwable $e): void
    {
        \Log::error('live_chat.notify_operators_failed_final', [
            'conversation_id' => $this->conversationId,
            'workspace_id' => $this->workspaceId,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $workspace = Workspace::query()
            ->withoutGlobalScopes()
            ->find($this->workspaceId);
        if ($workspace === null) {
            return;
        }

        $operators = User::query()
            ->where('users.live_chat_available', true)
            ->whereHas(
                'workspaces',
                fn ($q) => $q->where('workspaces.id', $workspace->id)
                    ->whereNotNull('workspace_users.accepted_at'),
            )
            ->get();

        if ($operators->isEmpty()) {
            return;
        }

        Notification::send(
            $operators,
            new HumanRequestedNotification(
                $this->conversationId,
                $this->workspaceId,
            ),
        );
    }
}
