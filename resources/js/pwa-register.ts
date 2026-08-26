/**
 * Register the admin shell service worker. Skipped when:
 *   - The page is loaded over plain HTTP (Brave/Chrome both block SW
 *     on insecure origins). Local Herd serves HTTPS by default; the
 *     guard only matters for misconfigured installs.
 *   - The browser doesn't support service workers (older Safari, etc).
 *   - The current page is a marketing/auth surface — SW is admin-only
 *     per the user's chosen scope.
 */
export function registerPwa(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!('serviceWorker' in navigator)) {
        return;
    }

    if (
        window.location.protocol !== 'https:' &&
        window.location.hostname !== 'localhost'
    ) {
        return;
    }

    const path = window.location.pathname;
    const isAdminShell =
        path.startsWith('/app') ||
        path.startsWith('/admin') ||
        path.startsWith('/settings') ||
        path.startsWith('/dashboard');

    if (!isAdminShell) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Best-effort — a registration failure shouldn't break
            // the page. The PWA layer is additive.
        });
    });
}
