<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Funnels super-admins away from customer surfaces. Anything under
 * /dashboard, /onboarding, /app/*, /billing/* is a tenant-scoped view
 * that doesn't belong on a platform operator's screen — they have
 * /admin for everything they need to do.
 *
 * If a super-admin needs to see what a specific customer sees, they
 * impersonate that customer (which flips the auth identity, so the
 * "is this user a super-admin?" check below evaluates to false on
 * subsequent requests).
 *
 * Self-host Docker installs (`PITCHBAR_SELF_HOST=true`) skip this
 * funnel: the first operator is both admin and workspace owner.
 */
class RedirectSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (config('app.self_host')) {
            return $next($request);
        }

        if ($user !== null && $user->isSuperAdmin()) {
            return redirect('/admin');
        }

        return $next($request);
    }
}
