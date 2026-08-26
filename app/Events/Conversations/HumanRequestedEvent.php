<?php

namespace App\Events\Conversations;

use App\Events\Concerns\BroadcastsWhenConfigured;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Visitor clicked "Connect me with a human" and the conversation is
 * now in the operator-side queue. Broadcast on:
 *
 *   - `conversation.{id}` — the visitor's widget hears it back so it
 *     can transition to a "Connecting you with someone…" state if it
 *     wasn't already.
 *   - `agent.{id}` — every operator tab subscribed to the agent gets
 *     a live ping that a new "needs human" request landed (drives the
 *     sidebar badge increment + a sonner toast in Phase 2).
 *
 * Phase 1 only consumes this on the visitor channel (so the holding
 * bubble + waiting state stay in sync if the visitor opens the widget
 * on multiple tabs). Phase 2 will add the agent-channel listener for
 * smart routing.
 */
class HumanRequestedEvent implements ShouldBroadcast
{
    use BroadcastsWhenConfigured, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $agentId,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->conversationId}"),
            new PrivateChannel("agent.{$this->agentId}.events"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.human-requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'agent_id' => $this->agentId,
            'at' => now()->toIso8601String(),
        ];
    }
}
