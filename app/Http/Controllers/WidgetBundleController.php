<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the canonical unhashed widget bundle at /widget/widget.js
 * with cache-busting response headers. Static-served files (Apache /
 * Nginx) hand out default ETag-only caching, and on shared hosts
 * many proxies + browser caches hold the file for days. That meant
 * customers embedding `<script src=".../widget/widget.js">` saw a
 * stale bundle even after the host re-deployed (buyer reported
 * 2026-05-21 — pulled, built, hard-refreshed, same broken UI).
 *
 * Setting `Cache-Control: no-cache, must-revalidate` forces every
 * fetch to revalidate against the server. ETag still works for the
 * 304 short-circuit so fresh deploys don't blow bandwidth; what
 * goes away is the implicit "max-age=infinity" behaviour of plain
 * static files behind a proxy.
 *
 * Hashed file `widget.<hash>.js` keeps long max-age — those URLs
 * mint a fresh hash per deploy so they're safe to cache hard.
 */
class WidgetBundleController
{
    public function __invoke(Request $request): BinaryFileResponse|Response
    {
        // Prefer the hashed bundle named in manifest.json so the
        // served content always matches whatever the post-build
        // committed for this deploy. Falls back to the unhashed
        // widget.js for legacy installs that haven't re-run the
        // post-build script.
        $manifestPath = public_path('widget/manifest.json');
        $path = null;
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && ! empty($manifest['file'])) {
                $candidate = public_path('widget/'.$manifest['file']);
                if (is_file($candidate)) {
                    $path = $candidate;
                }
            }
        }
        if ($path === null) {
            $path = public_path('widget/widget.js');
        }
        if (! is_file($path)) {
            return response(
                "// widget bundle not built — run `npm run build:widget`\n",
                404,
                ['Content-Type' => 'application/javascript'],
            );
        }

        $response = response()->file($path, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Strong ETag so clients can short-circuit with 304 when the
        // bundle hasn't changed. Hash is cheap (one md5 per request)
        // and lets the no-cache directive still save bandwidth.
        $response->setEtag((string) md5_file($path));
        $response->setLastModified(\DateTime::createFromFormat('U', (string) filemtime($path)));

        // isNotModified compares If-None-Match against our ETag (and
        // If-Modified-Since against Last-Modified). When the client
        // already has the current bundle, return 304 with the same
        // cache-control headers but no body — saves ~80 KB per
        // visit for cold-cached embedders.
        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
