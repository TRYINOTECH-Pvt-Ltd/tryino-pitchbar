<?php

namespace App\Http\Controllers\Admin\Platform;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['data' => $this->emptyEnvelope()]);
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        $workspaces = Workspace::query()->withoutGlobalScopes()
            ->with('owner:id,name,email')
            ->where(fn ($query) => $query
                ->where('name', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhereHas('owner', fn ($owner) => $owner
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)))
            ->latest()
            ->limit(5)
            ->get(['id', 'name', 'slug', 'owner_user_id'])
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'owner' => $workspace->owner?->email,
                'href' => route('admin.workspaces.show', $workspace, absolute: false),
            ]);

        $users = User::query()
            ->where(fn ($query) => $query
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like))
            ->latest()
            ->limit(5)
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value ?? 'customer',
                'href' => route('admin.users.index', ['q' => $user->email], absolute: false),
            ]);

        $agents = Agent::query()->withoutGlobalScopes()
            ->with('workspace:id,name')
            ->where(fn ($query) => $query
                ->where('name', 'like', $like)
                ->orWhereHas('workspace', fn ($workspace) => $workspace->where('name', 'like', $like)))
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'name', 'workspace_id', 'is_published'])
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'workspace_name' => $agent->workspace?->name,
                'is_published' => $agent->is_published,
                'href' => route('admin.agents.index', ['q' => $agent->name], absolute: false),
            ]);

        $leads = Lead::query()->withoutGlobalScopes()
            ->with(['agent:id,name,workspace_id', 'agent.workspace:id,name'])
            ->where(fn ($query) => $query
                ->where('email', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('phone', 'like', $like))
            ->latest()
            ->limit(5)
            ->get(['id', 'agent_id', 'email', 'name', 'status'])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'email' => $lead->email,
                'name' => $lead->name,
                'status' => $lead->status,
                'workspace_name' => $lead->agent?->workspace?->name,
                'href' => route('admin.leads.index', ['q' => $lead->email], absolute: false),
            ]);

        return response()->json([
            'data' => [
                'q' => $q,
                'workspaces' => $workspaces,
                'users' => $users,
                'agents' => $agents,
                'leads' => $leads,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyEnvelope(): array
    {
        return [
            'q' => '',
            'workspaces' => [],
            'users' => [],
            'agents' => [],
            'leads' => [],
        ];
    }
}
