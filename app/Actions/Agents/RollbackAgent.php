<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\AgentVersion;

class RollbackAgent
{
    public function handle(Agent $agent, AgentVersion $version): Agent
    {
        abort_if($version->agent_id !== $agent->id, 422, 'Version does not belong to this agent.');

        $snapshot = (array) $version->snapshot;

        $agent->forceFill([
            ...$snapshot,
            'published_version_id' => $version->id,
        ])->save();

        return $agent->fresh();
    }
}
