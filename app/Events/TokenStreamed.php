<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsWhenConfigured;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TokenStreamed implements ShouldBroadcastNow
{
    use BroadcastsWhenConfigured, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $token,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'token';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'token' => $this->token,
        ];
    }
}
