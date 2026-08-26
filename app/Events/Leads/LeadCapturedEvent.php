<?php

namespace App\Events\Leads;

use App\Events\Concerns\BroadcastsWhenConfigured;
use App\Models\Lead;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on the workspace's leads channel right after a Lead is
 * captured. The admin sidebar subscribes via Echo and shows a sonner
 * toast + (when granted) a native browser notification — the latter
 * means owners get pinged even on tabs that aren't focused.
 *
 * The payload is intentionally lean — just enough for the toast +
 * a deep-link to the inbox row. Anything richer rides the Inbox
 * Inertia visit triggered by clicking the toast.
 */
class LeadCapturedEvent implements ShouldBroadcast
{
    use BroadcastsWhenConfigured, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $workspaceId,
        public string $leadId,
        public string $email,
        public ?string $name,
        public ?string $phone,
        public string $agentName,
        public string $inboxUrl,
    ) {}

    public static function fromLead(Lead $lead, string $workspaceId, string $agentName): self
    {
        return new self(
            workspaceId: $workspaceId,
            leadId: (string) $lead->id,
            email: (string) $lead->email,
            name: $lead->name,
            phone: $lead->phone,
            agentName: $agentName,
            inboxUrl: '/app/inbox/'.$lead->id,
        );
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->workspaceId}.leads"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead.captured';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lead_id' => $this->leadId,
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone,
            'agent_name' => $this->agentName,
            'inbox_url' => $this->inboxUrl,
            'at' => now()->toIso8601String(),
        ];
    }
}
