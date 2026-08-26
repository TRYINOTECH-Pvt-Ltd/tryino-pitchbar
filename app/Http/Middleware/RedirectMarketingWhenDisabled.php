<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-level kill switch for the public marketing site.
 *
 * When `app_settings.marketing_site_enabled` is false:
 *   - unauthenticated visitors → redirected to /login.
 *   - authenticated customers → redirected to /dashboard.
 *   - platform super-admin → still sees the page so they can preview
 *     the marketing surfaces before flipping the switch back on.
 *
 * Hot-path-safe: AppSetting::singleton() is the cached singleton,
 * one in-memory lookup per request, no DB hit per page load.
 */
class RedirectMarketingWhenDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) (AppSetting::singleton()->marketing_site_enabled ?? true);
        if ($enabled) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return redirect('/login');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        return redirect('/dashboard');
    }
}
