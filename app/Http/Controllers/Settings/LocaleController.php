<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\I18n\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LocaleController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/locale', [
            'supported' => app(LocaleResolver::class)->supported(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(app(LocaleResolver::class)->supported())],
        ]);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }
        app()->setLocale($data['locale']);

        // Always set the locale cookie too so unauth visitors (geo
        // banner "switch" path) get persistence even though they have
        // no users.locale row to write to. SetLocale middleware reads
        // ?locale= → user.locale → Accept-Language; the cookie is
        // honoured via the `?locale=` redirect chain on the next visit
        // — the banner sets `?locale=es` once, then the user gets a
        // fresh session and the cookie carries forward across pages.
        $cookie = Cookie::make(
            name: 'pb_locale',
            value: $data['locale'],
            minutes: 60 * 24 * 365, // 1 year
        );

        return redirect()
            ->back()
            ->with('success', __('Saved language preference.'))
            ->withCookie($cookie);
    }

    /**
     * Visitor pressed "✕ Keep English" on the geo-suggested locale
     * banner — set a 180-day cookie so the banner doesn't reappear.
     * No body, no auth required (the banner shows on marketing pages
     * too, where the user isn't signed in).
     */
    public function dismissSuggestion(): SymfonyResponse
    {
        $cookie = Cookie::make(
            name: LocaleResolver::DISMISS_COOKIE,
            value: '1',
            minutes: 60 * 24 * 180, // 180 days
        );

        return response()->noContent()->withCookie($cookie);
    }
}
