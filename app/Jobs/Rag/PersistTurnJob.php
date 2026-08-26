<?php

namespace App\Jobs\Rag;

use App\Jobs\Analytics\RecomputeLeadScoreJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Experiments\ExperimentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PersistTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, array{id: int, url: ?string}>  $citations
     */
    public function __construct(
        public string $conversationId,
        public string $userMessageId,
        public string $userMessageText,
        public string $assistantMessageId,
        public string $assistantMessageText,
        public array $citations,
        public float $confidence,
        public int $latencyMs,
        public string $model,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(ExperimentResolver $experiments): void
    {
        // Instrument every failure path so when PersistUsageJob blows up
        // downstream with FK 1452 (cannot reference missing message_id),
        // the customer's prod log reveals which row never persisted.
        // Buyer-reported 2026-05-20: FK violations whose root cause
        // hadn't been observed in this layer.
        try {
            $conversation = Conversation::query()->withoutWorkspaceScope()->find($this->conversationId);
            if ($conversation === null) {
                Log::warning('persist_turn.conversation_missing', [
                    'conversation_id' => $this->conversationId,
                    'assistant_message_id' => $this->assistantMessageId,
                ]);

                return;
            }

            Message::create([
                'id' => $this->userMessageId,
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $this->userMessageText,
                'created_at' => now()->subMillis($this->latencyMs),
            ]);

            Message::create([
                'id' => $this->assistantMessageId,
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $this->assistantMessageText,
                'citations' => $this->citations,
                'confidence' => $this->confidence,
                'latency_ms' => $this->latencyMs,
                'model' => $this->model,
            ]);

            $conversation->forceFill([
                'message_count' => $conversation->message_count + 2,
            ])->save();

            $experiments->persistAssignment($conversation);

            RecomputeLeadScoreJob::dispatch($conversation->id);
        } catch (\Throwable $e) {
            Log::error('persist_turn.failed', [
                'conversation_id' => $this->conversationId,
                'user_message_id' => $this->userMessageId,
                'assistant_message_id' => $this->assistantMessageId,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            // Re-throw so dispatchSync caller sees the failure too —
            // the visitor's `done` event has already been sent at this
            // point, so the only impact is that the SSE socket closes
            // with an error trailer that the client ignores.
            throw $e;
        }
    }
}
