<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsWhenConfigured;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TurnCompleted implements ShouldBroadcastNow
{
    use BroadcastsWhenConfigured, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array{id: int, url: ?string}>  $citations
     * @param  array<string, mixed>|null  $cta
     */
    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $fullText,
        public array $citations = [],
        public ?array $cta = null,
        public bool $lowConfidence = false,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'turn.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'text' => $this->fullText,
            'citations' => $this->citations,
            'cta' => $this->cta,
            'low_confidence' => $this->lowConfidence,
        ];
    }
}
