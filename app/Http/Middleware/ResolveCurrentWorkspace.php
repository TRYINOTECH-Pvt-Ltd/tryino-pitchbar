<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentWorkspace
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $workspace = $this->resolveWorkspace($user);
            if ($workspace !== null) {
                $this->current->set($workspace);
                // Heal the user record: if they didn't have a default
                // (e.g., owner deleted, or this is the first workspace
                // membership the user was just added to), pin this one
                // so the next request doesn't have to scan again.
                if ($user->default_workspace_id !== $workspace->id) {
                    $user->forceFill(['default_workspace_id' => $workspace->id])->saveQuietly();
                }
            }
        }

        try {
            return $next($request);
        } finally {
            $this->current->clear();
        }
    }

    /**
     * Try the user's pinned default workspace first; if it's missing
     * or they're no longer a member, fall back to ANY workspace they
     * still belong to. Returns null only if they have zero memberships.
     */
    private function resolveWorkspace(User $user): ?Workspace
    {
        if ($user->default_workspace_id !== null) {
            $workspace = Workspace::query()
                ->withoutGlobalScopes()
                ->whereKey($user->default_workspace_id)
                ->whereHas('members', fn ($q) => $q->whereKey($user->id))
                ->first();
            if ($workspace !== null) {
                return $workspace;
            }
        }

        return Workspace::query()
            ->withoutGlobalScopes()
            ->whereHas('members', fn ($q) => $q->whereKey($user->id))
            ->orderBy('created_at')
            ->first();
    }
}
