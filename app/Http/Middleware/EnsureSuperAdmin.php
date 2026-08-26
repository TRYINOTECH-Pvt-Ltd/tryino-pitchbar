<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the platform admin area (/admin/*).
 *
 * Returns 404 (not 403) for non-admins so the existence of the area is
 * not advertised to regular customers via URL probing.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Impersonation escape hatch: when a super-admin is impersonating
        // a customer, `auth()->user()` returns the customer (who isn't a
        // super-admin), so /admin/* used to 404 mid-session. Fall back
        // to the impersonator stamped on the session so the admin can
        // still reach platform pages without un-impersonating first.
        $impersonatorId = $request->session()->get('impersonator_id');
        if ($impersonatorId !== null) {
            $impersonator = User::query()->find($impersonatorId);
            if ($impersonator !== null && $impersonator->isSuperAdmin()) {
                return $next($request);
            }
        }

        abort(404);
    }
}
