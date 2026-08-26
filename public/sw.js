/**
 * Pitchbar admin shell service worker.
 *
 * Strategy:
 *   - HTML shells (Inertia routes under /app, /admin, /settings):
 *     network-first with a 4-second timeout, falling back to the
 *     cached shell so a flaky-mobile-data operator still sees the
 *     last-known view.
 *   - Vite bundles under /build/* (immutable content-hashed URLs):
 *     cache-first, forever. Vite's hash already busts on every build.
 *   - Manifest / icons under /icons/* / /favicon.ico: cache-first.
 *   - Mutation endpoints (POST/PATCH/DELETE) and /api/v1/widget/*:
 *     network-only, never cached. Widget surfaces are visitor-facing
 *     and must not be intercepted.
 *
 * Cache invalidation: every version bump below busts the shell cache.
 * The hash auto-rolls every deploy — service worker activate purges
 * all caches that don't match.
 */
// v2: bust the cached 404 shell from the broken `start_url=/app`
// manifest (client report 2026-05-25). On `activate`, the cleanup
// loop below deletes every cache that doesn't start with this
// VERSION — existing installs purge the stale shell on next visit.
const VERSION = 'pitchbar-shell-v2';
const SHELL_CACHE = `${VERSION}-shell`;
const ASSET_CACHE = `${VERSION}-assets`;

const SHELL_TIMEOUT_MS = 4000;

const NETWORK_ONLY_PREFIXES = [
    '/api/',
    '/_internal/',
    '/billing/webhook',
    '/stripe/webhook',
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => !key.startsWith(VERSION))
                    .map((stale) => caches.delete(stale)),
            ),
        ),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return; // POST/PATCH/DELETE → straight to network.
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (NETWORK_ONLY_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(cacheFirst(request, ASSET_CACHE));
        return;
    }

    if (request.mode === 'navigate' || request.destination === 'document') {
        event.respondWith(navigationStrategy(request));
    }
});

async function navigationStrategy(request) {
    const cache = await caches.open(SHELL_CACHE);

    try {
        const networkPromise = fetch(request);
        const timeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error('shell-timeout')), SHELL_TIMEOUT_MS),
        );
        const response = await Promise.race([networkPromise, timeoutPromise]);

        if (response && response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch (err) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }

        // Last-ditch: serve a tiny offline notice. Operators on the
        // road with no signal see something better than the browser's
        // generic "no internet" page.
        return new Response(
            '<!doctype html><meta charset=utf-8><title>Offline</title>' +
                '<style>body{font:14px/1.5 system-ui;padding:32px;color:#0f172a}</style>' +
                "<h1>You're offline</h1><p>The page isn't in your local cache yet. " +
                'Connect to the internet and refresh.</p>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 },
        );
    }
}

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }

    const response = await fetch(request);
    if (response && response.ok && response.status < 400) {
        cache.put(request, response.clone());
    }

    return response;
}
