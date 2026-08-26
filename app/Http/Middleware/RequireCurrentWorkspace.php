<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer surfaces (anything under /app/*) all need a current workspace
 * to make sense — agents, sources, inbox, analytics, billing, etc. all
 * scope through workspace_id.
 *
 * Without this middleware, hitting /app/agents with no workspace returns
 * a bare 404 (each controller has its own abort_if). Confusing during
 * impersonation: super-admin impersonates an orphan user → entire
 * customer shell is unusable. We bounce to /dashboard, which already
 * renders gracefully for the no-workspace case.
 */
class RequireCurrentWorkspace
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->current->id() === null) {
            // /dashboard handles the empty state — friendly, no 404 spiral.
            return redirect('/dashboard')->with(
                'error',
                "You're not a member of any workspace yet. Ask an admin to invite you, or create a new workspace."
            );
        }

        return $next($request);
    }
}
