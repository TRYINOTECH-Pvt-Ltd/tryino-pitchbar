<?php

namespace App\Concerns;

use App\Models\Agent;
use App\Scopes\WorkspaceScope;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Models that scope through `agent_id` rather than `workspace_id`.
 * Resolves the workspace via the agent's relationship and inherits the
 * workspace global scope by joining through agents.
 */
trait BelongsToAgent
{
    public static function bootBelongsToAgent(): void
    {
        static::addGlobalScope('agentBelongsToWorkspace', function (Builder $builder): void {
            $workspaceId = app(CurrentWorkspace::class)->id();
            if ($workspaceId === null) {
                return;
            }

            $builder->whereHas('agent', function (Builder $q) use ($workspaceId): void {
                $q->where('workspace_id', $workspaceId);
            });
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function scopeWithoutWorkspaceScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('agentBelongsToWorkspace')
            ->withoutGlobalScope(WorkspaceScope::class);
    }
}
