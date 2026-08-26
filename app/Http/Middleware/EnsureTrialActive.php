<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trial wall. Once a workspace's no-card trial has expired and nothing
 * paid has taken its place, the customer surfaces under /app/* are
 * blocked and the user is bounced to the billing page to upgrade.
 *
 * Billing routes are allow-listed so the upgrade path itself stays
 * reachable (and to avoid a redirect loop, since billing.show lives in
 * the same gated group). Account management, logout, and the dashboard
 * sit in separate route groups, so an expired user can still manage
 * their profile, sign out, or switch workspaces.
 */
class EnsureTrialActive
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The upgrade path must always be reachable — otherwise the wall
        // traps the user with no way to pay.
        if ($request->routeIs('billing.*')) {
            return $next($request);
        }

        $workspace = $this->current->get();

        if ($workspace !== null && $workspace->trialExpired()) {
            return redirect()
                ->route('billing.show')
                ->with('error', __('Your free trial has ended. Upgrade to a paid plan to continue using the platform.'));
        }

        return $next($request);
    }
}
