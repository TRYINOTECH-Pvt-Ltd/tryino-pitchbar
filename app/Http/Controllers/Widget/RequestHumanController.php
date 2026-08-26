<?php

namespace App\Http\Controllers\Widget;

use App\Events\Conversations\HumanRequestedEvent;
use App\Jobs\LiveChat\NotifyOperatorsHumanRequestedJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\LiveChat\BusinessHours;
use App\Services\LiveChat\WorkspacePresence;
use App\Services\Widget\WidgetJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitor-side "I want to talk to a human" signal.
 *
 * Fired when the visitor clicks the escalation pill (or any future UI
 * surface that wants to flag a conversation for operator attention).
 * Sets `conversations.human_requested_at` so the operator-side queue
 * can surface it under the "Needs human" filter, broadcasts a
 * `HumanRequestedEvent` so an admin tab gets the live ping, and
 * causes `MessageStreamController` to serve a holding bubble instead
 * of an LLM reply on subsequent turns until an operator claims.
 *
 * Idempotent: a second click during the same waiting window doesn't
 * reset the timestamp (so the waiting indicator's "X min ago" stays
 * truthful) and doesn't re-broadcast.
 */
class RequestHumanController
{
    /**
     * How long the widget shows "Connecting you with someone…" before
     * it flips locally to the offline / leave-email banner. 120s is
     * long enough for an admin tab to wake up + claim, short enough
     * that a visitor doesn't stare at a spinner forever. The dashboard
     * still sees the request in its "needs human" queue past this
     * window — the timer is purely a visitor-side UX gate.
     */
    public const WAIT_TIMEOUT_SECONDS = 120;

    public function __construct(
        private WidgetJwt $jwt,
        private WorkspacePresence $presence,
        private BusinessHours $businessHours,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Widget-Token');
        if (! is_string($token)) {
            return response()->json(['error' => ['code' => 'missing_token']], 401);
        }
        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'invalid_token']], 401);
        }

        $conversationId = (string) ($claims['conversation_id'] ?? '');
        if ($conversationId === '') {
            return response()->json(['error' => ['code' => 'no_conversation']], 422);
        }

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->find($conversationId);
        if ($conversation === null) {
            return response()->json(['error' => ['code' => 'conversation_not_found']], 404);
        }

        // Workspace context for smart routing. We need it before
        // we decide whether to flag the conversation: if the routing
        // decides "we're offline, leave email", we should NOT set
        // `human_requested_at` (otherwise the holding-bubble branch in
        // MessageStreamController fires on the next visitor turn).
        $agent = Agent::query()->withoutGlobalScopes()->find($conversation->agent_id);
        $workspace = $agent !== null
            ? Workspace::query()->withoutGlobalScopes()->find($agent->workspace_id)
            : null;

        $status = $this->resolveStatus($conversation, $workspace);

        // Always queue the request + notify operators, regardless of
        // presence. Pre-fix the controller answered "no one's around"
        // synchronously when presence=0 and never fired the event — so
        // the dashboard heard nothing, no email went out, and the
        // visitor couldn't reach anyone even if an operator opened the
        // tab one minute later. New flow: persist the request, notify
        // every available operator (Reverb + DB notification + email +
        // Slack/Teams via existing LiveChatNotifier), and tell the
        // widget to spin for WAIT_TIMEOUT_SECONDS. After that window,
        // the widget locally flips to the offline / leave-email banner.
        //
        // The one exception is `offline_after_hours`: business hours
        // are an explicit operator config saying "we don't take chats
        // outside this window", so we keep the request in the queue
        // for tomorrow's shift but tell the widget to render the
        // after-hours copy now instead of a 2-minute wait.
        $alreadyWaiting = $conversation->human_requested_at !== null
            && $conversation->claimed_by_user_id === null;

        if (
            $status !== 'queued_after_hours'
            && ! $alreadyWaiting
            && $conversation->claimed_by_user_id === null
        ) {
            $conversation->forceFill(['human_requested_at' => now()])->save();
            HumanRequestedEvent::dispatch(
                $conversation->id,
                (string) $conversation->agent_id,
            );
            if ($workspace !== null) {
                NotifyOperatorsHumanRequestedJob::dispatch(
                    $conversation->id,
                    (string) $workspace->id,
                );
            }
        } elseif ($status === 'queued_after_hours' && ! $alreadyWaiting) {
            // Still persist the timestamp so the operator's "needs
            // human" filter surfaces the request tomorrow.
            $conversation->forceFill(['human_requested_at' => now()])->save();
            HumanRequestedEvent::dispatch(
                $conversation->id,
                (string) $conversation->agent_id,
            );
            if ($workspace !== null) {
                NotifyOperatorsHumanRequestedJob::dispatch(
                    $conversation->id,
                    (string) $workspace->id,
                );
            }
        }

        $next_open_at = $status === 'queued_after_hours' && $workspace !== null
            ? $this->businessHours->nextOpenAt($workspace)
            : null;

        // Widget contract: the legacy `offline_after_hours` string is
        // returned for the after-hours case so existing widget builds
        // still render the right banner. New widget consumes
        // `wait_timeout_seconds` to gate the spinner.
        $publicStatus = match ($status) {
            'queued_after_hours' => 'offline_after_hours',
            default => 'queued',
        };

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'human_requested_at' => $conversation->human_requested_at?->toIso8601String(),
                'claimed' => $conversation->claimed_by_user_id !== null,
                'status' => $publicStatus,
                'next_open_at' => $next_open_at,
                'wait_timeout_seconds' => $publicStatus === 'queued'
                    ? self::WAIT_TIMEOUT_SECONDS
                    : 0,
                'active_operator_count' => $workspace !== null
                    ? $this->presence->activeOperatorCount($workspace)
                    : 0,
            ],
        ]);
    }

    /**
     * Decision tree:
     *   - already claimed → 'queued' (idempotent; widget sees claimed=true)
     *   - business hours configured + currently closed → 'queued_after_hours'
     *   - else → 'queued' regardless of operator presence. The widget
     *     waits WAIT_TIMEOUT_SECONDS for a claim and falls back to the
     *     leave-email banner locally if nothing happens.
     */
    private function resolveStatus(Conversation $conversation, ?Workspace $workspace): string
    {
        if ($conversation->claimed_by_user_id !== null) {
            return 'queued';
        }
        if ($workspace === null) {
            return 'queued';
        }
        if (! $this->businessHours->isOpen($workspace)) {
            return 'queued_after_hours';
        }

        return 'queued';
    }
}
