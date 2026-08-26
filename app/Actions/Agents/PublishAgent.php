<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\AgentVersion;
use App\Models\User;

class PublishAgent
{
    public function handle(Agent $agent, ?User $by = null): AgentVersion
    {
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'snapshot' => $agent->only([
                'name', 'language_default', 'persona', 'theme',
                'allowed_origins', 'system_prompt', 'guardrails',
                'confidence_threshold',
            ]),
            'created_by' => $by?->id,
        ]);

        $agent->forceFill([
            'is_published' => true,
            'published_version_id' => $version->id,
        ])->save();

        return $version;
    }
}
