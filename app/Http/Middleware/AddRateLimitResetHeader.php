<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel ships X-RateLimit-Limit + X-RateLimit-Remaining on every
 * throttled response, but only adds X-RateLimit-Reset on a 429. API
 * consumers want the reset value on every response so they can pace
 * themselves BEFORE hitting the wall. Compute the reset as
 * (now + 60s) — coarse but accurate for per-minute limiters which
 * cover every public surface today.
 */
class AddRateLimitResetHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('X-RateLimit-Limit')) {
            return $response;
        }

        if ($response->headers->has('X-RateLimit-Reset')) {
            return $response;
        }

        $response->headers->set('X-RateLimit-Reset', (string) (time() + 60));

        return $response;
    }
}
