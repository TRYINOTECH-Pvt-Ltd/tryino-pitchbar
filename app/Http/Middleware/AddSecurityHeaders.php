<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Append browser-level defence headers to every web response. These
 * are layered on top of the controller-side guards (KB markdown
 * sanitization, widget Origin re-check, mass-assignment Fillable
 * cleanup) so the browser refuses to honour exploit attempts even
 * if a future code change re-introduces a stored-XSS sink.
 *
 *   - Content-Security-Policy: blocks inline event handlers
 *     (`<img onerror=...>`) and javascript: URIs in `href`/`src`,
 *     even on /kb/* pages where operator-supplied markdown lives.
 *     `script-src 'self' 'unsafe-inline'` is generous on purpose
 *     because Inertia bootstraps via an inline JSON payload that
 *     would otherwise need a nonce + Laravel-wide refactor; we
 *     can tighten this once we ship a nonce middleware.
 *   - Strict-Transport-Security: enforces HTTPS for a year. Only
 *     emitted on already-HTTPS requests so a misconfigured local
 *     env doesn't pin HSTS to http://app.test.
 *   - X-Content-Type-Options: blocks IE/Edge MIME-sniffing.
 *   - Referrer-Policy: trims the leaked Referer to the bare
 *     origin on cross-origin navigations. Default `no-referrer-
 *     when-downgrade` leaks the full URL — too much info for
 *     pages like /kb/ that include workspace + article slugs.
 *
 * Scoped to the `web` middleware group, NOT API. The widget API
 * is intentionally embeddable cross-origin (buyers' marketing
 * sites), so `frame-ancestors 'self'` would break them.
 */
class AddSecurityHeaders
{
    private const CSP = <<<'CSP'
        default-src 'self';
        script-src 'self' 'unsafe-inline' 'unsafe-eval' https:;
        style-src 'self' 'unsafe-inline' https:;
        img-src 'self' data: https: blob:;
        font-src 'self' data: https:;
        connect-src 'self' https: wss:;
        frame-src 'self' https:;
        media-src 'self' https:;
        object-src 'none';
        base-uri 'self';
        form-action 'self';
        frame-ancestors 'self';
        CSP;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip non-HTML responses (downloads, file streams,
        // Inertia partial JSON updates). CSP only meaningfully
        // gates browser rendering of an HTML/JS context.
        $contentType = (string) $response->headers->get('Content-Type', '');
        $isHtml = str_contains($contentType, 'text/html') || $contentType === '';

        if ($isHtml && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set(
                'Content-Security-Policy',
                trim(preg_replace('/\s+/', ' ', self::CSP) ?? ''),
            );
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            // 1 year + subdomains. Production runs on Laravel Cloud
            // behind real TLS; HSTS pinning is safe.
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
