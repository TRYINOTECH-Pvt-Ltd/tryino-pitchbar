<?php

namespace App\Http\Controllers\Admin;

use App\Events\Conversations\AgentReplyPostedEvent;
use App\Events\Conversations\ConversationClaimedEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkspaceUser;
use App\Support\TokenEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Live human takeover. A workspace member claims an in-flight conversation,
 * the bot stops auto-responding for that session (RagPipeline checks
 * `claimed_by_user_id`), and the human can post replies that broadcast over
 * Reverb to the visitor's widget.
 */
class ConversationTakeoverController
{
    public function claim(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        if ($conversation->claimed_by_user_id !== null
            && (int) $conversation->claimed_by_user_id !== (int) $request->user()->id) {
            $other = $conversation->claimedBy;

            return response()->json([
                'error' => [
                    'code' => 'already_claimed',
                    'by' => $other?->only('id', 'name'),
                ],
            ], 409);
        }

        $conversation->forceFill([
            'claimed_by_user_id' => $request->user()->id,
            'claimed_at' => now(),
        ])->save();

        ConversationClaimedEvent::dispatch(
            $conversation->id,
            (string) $request->user()->id,
            (string) $request->user()->name,
        );

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'claimed_by' => $request->user()->only('id', 'name'),
                'claimed_at' => $conversation->claimed_at?->toIso8601String(),
            ],
        ]);
    }

    public function release(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        // Only the current claimant (or workspace admin) can release.
        if ($conversation->claimed_by_user_id !== null
            && (int) $conversation->claimed_by_user_id !== (int) $request->user()->id) {
            // Allow admins to break-the-glass. Re-use the existing policy.
            $request->user()->can('update', $conversation->agent) || abort(403);
        }

        // Remember whether an operator was actually in the chat BEFORE
        // we clear the flags — drives the "should we post a one-line
        // 'AI is back' note?" decision below. Release-on-unclaimed
        // (race condition, double-click, admin clearing a stale queue
        // entry) must NOT post "operator stepped away" — there was no
        // operator to step away.
        $wasClaimed = $conversation->claimed_by_user_id !== null;

        // Buyer-reported (Lucian, 2026-05-15): after operator release the
        // visitor's "Connecting you with someone…" banner persisted forever
        // because `human_requested_at` was left set; visitor's UI treats
        // (human_requested_at != null AND claimed_by null) as "still
        // queued for an operator". Clear the flag on release so the
        // visitor returns to AI-assistance state. Operator-typing is
        // also cleared so the visitor doesn't see a frozen "is typing…"
        // indicator after the operator leaves.
        $conversation->forceFill([
            'claimed_by_user_id' => null,
            'claimed_at' => null,
            'human_requested_at' => null,
            'operator_typing_until' => null,
        ])->save();

        if ($wasClaimed) {
            // Surface the transition in the chat as a one-line system
            // note. Stored as a `human:<uid>` message so the visitor's
            // poll picks it up via ConversationMessagesController (which
            // filters `model LIKE 'human:%'`). content is short, no PII.
            $message = Message::create([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => 'The operator has stepped away. The AI assistant is back to help.',
                'citations' => [],
                'confidence' => 1.0,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'latency_ms' => 0,
                'model' => 'human:'.$request->user()->id,
            ]);

            // Mirror claim()'s broadcast — release was previously silent
            // and the visitor's banner only cleared on the next 3-second
            // poll. Dispatching AgentReplyPostedEvent pushes the system
            // message to the widget's open Reverb channel immediately so
            // the chat history updates without a poll round-trip.
            AgentReplyPostedEvent::dispatch(
                $conversation->id,
                $message->id,
                $message->content,
                (string) $request->user()->name,
            );
        }

        return response()->json(['data' => ['conversation_id' => $conversation->id]]);
    }

    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        if ((int) $conversation->claimed_by_user_id !== (int) $request->user()->id) {
            // Diagnostic log — buyer (Lucian, 2026-05-15/16) kept hitting
            // must_claim_first after a successful claim on his shared-
            // host install. Log the actual values so the next report
            // includes data we can act on (string vs int hydration,
            // null after race, wrong account etc.).
            \Log::warning('takeover.reply.claim_mismatch', [
                'conversation_id' => $conversation->id,
                'claimed_by_user_id_raw' => $conversation->getAttributes()['claimed_by_user_id'] ?? null,
                'claimed_by_user_id_typed' => $conversation->claimed_by_user_id,
                'request_user_id' => $request->user()->id,
                'request_user_id_type' => gettype($request->user()->id),
            ]);

            return response()->json([
                'error' => ['code' => 'must_claim_first'],
            ], 409);
        }

        $message = Message::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $data['content'],
            'citations' => [],
            'confidence' => 1.0,
            'tokens_in' => 0,
            'tokens_out' => TokenEstimator::estimate($data['content']),
            'latency_ms' => 0,
            'model' => 'human:'.$request->user()->id,
        ]);

        $conversation->forceFill([
            'message_count' => ($conversation->message_count ?? 0) + 1,
        ])->save();

        AgentReplyPostedEvent::dispatch(
            $conversation->id,
            $message->id,
            $message->content,
            (string) $request->user()->name,
        );

        return response()->json([
            'data' => [
                'message_id' => $message->id,
                'content' => $message->content,
            ],
        ]);
    }

    /**
     * Internal note — operator-to-operator context that the visitor
     * never sees. Persists as a Message with `role='internal-note'`;
     * the visitor-side poll endpoint already filters to
     * `model LIKE 'human:%'` so notes are naturally invisible there.
     * No broadcast — operators see notes via the standard message log
     * polling.
     */
    public function note(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        $message = Message::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'role' => 'internal-note',
            'content' => $data['content'],
            'citations' => [],
            'confidence' => null,
            'tokens_in' => 0,
            'tokens_out' => TokenEstimator::estimate($data['content']),
            'latency_ms' => 0,
            'model' => 'note:'.$request->user()->id,
        ]);

        return response()->json([
            'data' => [
                'message_id' => $message->id,
                'content' => $message->content,
                'author_name' => $request->user()->name,
            ],
        ]);
    }

    /**
     * Operator typing indicator. Sets the conversation's
     * `operator_typing_until` to a 5-second window so the visitor's
     * existing poll can render "is typing…" without us adding a new
     * broadcast channel.
     *
     * Throttled at the route level (60/min) — the frontend debounces
     * keystrokes to one POST every 2 seconds while the operator is
     * actively typing, so this ceiling is comfortable.
     */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        $conversation->forceFill([
            'operator_typing_until' => now()->addSeconds(5),
        ])->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Transfer the active claim to another workspace operator.
     * Validates the target is a member of the agent's workspace AND
     * has `update` permission on the agent (same gate as Claim).
     * Emits ConversationClaimedEvent so the visitor's banner refreshes
     * to "<New Operator> joined the chat" without them needing to
     * reload.
     *
     * Drops a system-flagged message in the thread so the conversation
     * history shows the handoff context for the receiving operator.
     */
    public function transfer(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize($request, $conversation);

        $data = $request->validate([
            'target_user_id' => ['required', 'integer'],
        ]);

        // Only the current claimant (or admin) may transfer.
        if (
            $conversation->claimed_by_user_id !== null
            && (int) $conversation->claimed_by_user_id !== (int) $request->user()->id
        ) {
            $request->user()->can('update', $conversation->agent) || abort(403);
        }

        $agent = $conversation->agent()->withoutWorkspaceScope()->first();
        $target = User::query()->find($data['target_user_id']);
        if ($target === null) {
            abort(422, 'Target user not found.');
        }

        // Target must be a workspace member with `update` permission.
        $isMember = WorkspaceUser::query()
            ->where('workspace_id', $agent->workspace_id)
            ->where('user_id', $target->id)
            ->whereNotNull('accepted_at')
            ->exists();
        if (! $isMember) {
            abort(422, 'Target is not a member of this workspace.');
        }
        if (! $target->can('update', $agent)) {
            abort(422, 'Target does not have permission on this agent.');
        }

        $previousName = $request->user()->name;

        $conversation->forceFill([
            'claimed_by_user_id' => $target->id,
            'claimed_at' => now(),
        ])->save();

        ConversationClaimedEvent::dispatch(
            $conversation->id,
            (string) $target->id,
            (string) $target->name,
        );

        // Audit message — visible to both operators in the thread,
        // not surfaced to the visitor (visitor poll filters out
        // system-flagged messages).
        Message::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'role' => 'internal-note',
            'content' => "{$previousName} transferred this conversation to {$target->name}.",
            'citations' => [],
            'confidence' => null,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'model' => 'system:transfer',
        ]);

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'claimed_by' => ['id' => $target->id, 'name' => $target->name],
            ],
        ]);
    }

    private function authorize(Request $request, Conversation $conversation): void
    {
        $agent = $conversation->agent()->withoutWorkspaceScope()->first();
        abort_if($agent === null, 404);
        $request->user()->can('update', $agent) || abort(403);
    }
}
