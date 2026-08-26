import { useEffect } from 'react';

/**
 * Admin-tab presence heartbeat.
 *
 * Pings POST /app/me/presence every 60 seconds while the tab is the
 * active foreground tab. The endpoint bumps users.last_active_at,
 * which WorkspacePresence reads to decide whether the visitor's
 * "Connect me with a human" pill should queue them or fall back to
 * the "we're offline" treatment.
 *
 * Pauses when the tab is hidden (battery + cost) and on logout. Runs
 * regardless of whether the user has opted into live chat — opt-in is
 * read server-side; sending the heartbeat unconditionally keeps the
 * shell free of policy logic.
 */
const HEARTBEAT_INTERVAL_MS = 60_000;

export function usePresence(enabled: boolean): void {
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const csrf =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? '';

        const tick = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            fetch('/app/me/presence', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            }).catch(() => {
                // Best-effort. A missed heartbeat is fine — the next
                // one will refresh the timestamp.
            });
        };

        tick();
        const id = window.setInterval(tick, HEARTBEAT_INTERVAL_MS);

        return () => {
            window.clearInterval(id);
        };
    }, [enabled]);
}
