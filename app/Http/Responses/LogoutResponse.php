<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Inertia-aware logout response.
 *
 * Fortify's default response 302-redirects to /, but the home page is a
 * Blade view (the marketing site) — not an Inertia page. When an Inertia
 * XHR request follows the 302, it gets back HTML instead of an Inertia
 * JSON payload, which (depending on the client version) leaves the
 * previous admin/customer page rendered with the marketing page glued
 * on top as a modal-looking overlay.
 *
 * Inertia::location() sends a 409 Conflict + X-Inertia-Location header,
 * which the Inertia client knows means "do a hard browser navigation to
 * this URL" — clean, no overlay, no stale state.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request)
    {
        $target = Fortify::redirects('logout', '/');

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect($target);
    }
}
