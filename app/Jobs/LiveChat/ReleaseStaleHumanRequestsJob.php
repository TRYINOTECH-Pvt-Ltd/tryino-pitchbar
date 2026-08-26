<?php

namespace App\Jobs\LiveChat;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Sweeps conversations that asked for a human but never got claimed.
 *
 * If a conversation has been sitting in the "Needs human" state for
 * more than {@see self::STALE_AFTER_MINUTES} minutes, we:
 *
 *   1. Clear `human_requested_at` so the holding-bubble short-circuit
 *      in MessageStreamController stops firing — the bot resumes on
 *      the visitor's next turn.
 *   2. Drop a closure message so the conversation doesn't end on a
 *      ghost note. The visitor sees "Looks like everyone's busy at the
 *      moment — leave your email and we'll follow up." next time they
 *      open the widget or send another turn.
 *
 * Scheduled every minute. Cheap — single indexed query on the
 * `conversations_needs_human_idx` compound index.
 */
class ReleaseStaleHumanRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How long an unclaimed human-request can sit before we declare it
     * stale and resume the bot. Phase 2 default — Phase 3 will make this
     * a per-workspace knob.
     */
    public const STALE_AFTER_MINUTES = 5;

    public function handle(): void
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        $stale = Conversation::query()
            ->withoutGlobalScopes()
            ->whereNotNull('human_requested_at')
            ->whereNull('claimed_by_user_id')
            ->where('human_requested_at', '<', $cutoff)
            ->get();

        foreach ($stale as $conversation) {
            $conversation->forceFill(['human_requested_at' => null])->save();

            // System closure note — gives the visitor a next step
            // instead of an indefinite "operator joining…" bubble.
            // Surfaces as an assistant message so it lands in the
            // visitor's chat history just like any LLM reply.
            Message::create([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => "Looks like everyone's busy at the moment. Could you leave your email and we'll follow up as soon as we can?",
                'citations' => [],
                'confidence' => 1.0,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'latency_ms' => 0,
                'model' => 'system:auto-fallback',
            ]);
        }
    }
}
