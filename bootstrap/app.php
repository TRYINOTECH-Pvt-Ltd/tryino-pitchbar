<?php

use App\Http\Middleware\AddRateLimitResetHeader;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTrialActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectSuperAdmin;
use App\Http\Middleware\RequireCurrentWorkspace;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyHmacSignature;
use App\Http\Middleware\VerifyWidgetOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Docker / Nginx Proxy Manager / Cloudflare terminate TLS in front
        // of FrankenPHP. Trust all proxies so HTTPS, the real client IP,
        // and signed widget URLs stay correct.
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'pb_locale', 'pb_locale_dismiss']);

        $middleware->api(append: [
            AddRateLimitResetHeader::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            ResolveCurrentWorkspace::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Defence-in-depth headers — CSP, HSTS, nosniff,
            // referrer policy. Scoped to web (not the widget API,
            // which buyers embed cross-origin and would break
            // under frame-ancestors 'self').
            AddSecurityHeaders::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'billing/webhook',
            'billing/webhook/paypal',
            'billing/webhook/razorpay',
        ]);

        $middleware->alias([
            'workspace.resolve' => ResolveCurrentWorkspace::class,
            'workspace.require' => RequireCurrentWorkspace::class,
            'trial.active' => EnsureTrialActive::class,
            'super_admin' => EnsureSuperAdmin::class,
            'redirect.super_admin' => RedirectSuperAdmin::class,
            'auth.api_token' => AuthenticateApiToken::class,
            'hmac.signature' => VerifyHmacSignature::class,
            'widget.origin' => VerifyWidgetOrigin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
