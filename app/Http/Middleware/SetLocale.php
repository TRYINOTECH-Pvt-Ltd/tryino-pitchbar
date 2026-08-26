<?php

namespace App\Http\Middleware;

use App\Services\I18n\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        // When the multilingual layer is off, the site is English-only:
        // ignore the visitor's ?locale / stored locale / Accept-Language
        // entirely and pin the configured app locale.
        $locale = config('app.multilingual_enabled')
            ? $this->resolver->forRequest($request)
            : (string) config('app.locale', 'en');

        app()->setLocale($locale);

        return $next($request);
    }
}
