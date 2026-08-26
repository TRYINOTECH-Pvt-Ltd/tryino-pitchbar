<?php

namespace App\Services\Analytics;

use App\Models\Event;

class EventStore
{
    public function record(?string $workspaceId, ?string $agentId, ?string $conversationId, string $kind, array $payload = []): void
    {
        Event::create([
            'workspace_id' => $workspaceId,
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
            'kind' => $kind,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
