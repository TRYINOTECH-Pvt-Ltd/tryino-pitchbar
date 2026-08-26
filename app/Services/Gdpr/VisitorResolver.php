<?php

namespace App\Services\Gdpr;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Visitor;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Resolve which visitor rows belong to the data subject identified
 * by email, visitor uuid, or anonymous id. Scoped to one workspace
 * — cross-workspace lookups would leak PII between tenants.
 */
class VisitorResolver
{
    /**
     * @param  array{email?:string|null,visitor_id?:string|null,anonymous_id?:string|null}  $criteria
     * @return Collection<int,Visitor>
     */
    public function resolve(Workspace $workspace, array $criteria): Collection
    {
        $agentIds = Agent::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->pluck('id');

        if ($agentIds->isEmpty()) {
            return collect();
        }

        $visitorIds = collect();

        $email = isset($criteria['email']) ? trim((string) $criteria['email']) : '';
        if ($email !== '') {
            $fromLeads = Lead::query()
                ->withoutGlobalScopes()
                ->whereIn('agent_id', $agentIds)
                ->where('email', $email)
                ->pluck('conversation_id');

            if ($fromLeads->isNotEmpty()) {
                $visitorIds = $visitorIds->merge(
                    Conversation::query()
                        ->withoutGlobalScopes()
                        ->whereIn('id', $fromLeads)
                        ->pluck('visitor_id')
                );
            }
        }

        $visitorId = isset($criteria['visitor_id']) ? trim((string) $criteria['visitor_id']) : '';
        if ($visitorId !== '') {
            $visitorIds->push($visitorId);
        }

        $anonymousId = isset($criteria['anonymous_id']) ? trim((string) $criteria['anonymous_id']) : '';
        if ($anonymousId !== '') {
            $fromAnon = Visitor::query()
                ->withoutGlobalScopes()
                ->whereIn('agent_id', $agentIds)
                ->where('anonymous_id', $anonymousId)
                ->pluck('id');

            $visitorIds = $visitorIds->merge($fromAnon);
        }

        $visitorIds = $visitorIds->filter()->unique()->values();

        if ($visitorIds->isEmpty()) {
            return collect();
        }

        return Visitor::query()
            ->withoutGlobalScopes()
            ->whereIn('agent_id', $agentIds)
            ->whereIn('id', $visitorIds)
            ->get();
    }
}
