import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Options = {
    /**
     * When false, the hook stops polling — used by the board page to
     * pause refreshes while a Create / Edit dialog is open so the
     * user's form data never gets replaced under their cursor.
     */
    enabled: boolean;
    /**
     * Polling cadence in ms. Default 8s — fast enough that two
     * super-admins coordinating see each other's moves quickly,
     * slow enough that an idle dashboard isn't burning HTTP.
     */
    intervalMs?: number;
    /**
     * Inertia partial-reload prop key(s) to refresh. Default
     * `['tasks']` — matches the board page's prop name.
     */
    only?: string[];
};

type Result = {
    /** Server time of the last successful refresh, or null before the first. */
    lastSyncedAt: Date | null;
    /** True while a refresh is in flight. */
    isSyncing: boolean;
};

/**
 * Real-time updates for an Inertia page via background polling.
 *
 * Memory-leak guarantees the hook upholds:
 *
 *   1. Single-flight: `inFlightRef` prevents a new poll while the
 *      previous one is still running, so a slow connection can't pile
 *      up requests.
 *
 *   2. Interval cleanup: the interval ID lives in a ref; the effect's
 *      cleanup clears it AND every dependency change re-creates a
 *      fresh interval after clearing the old one.
 *
 *   3. Listener cleanup: the visibilitychange listener is added and
 *      removed inside the same effect, so it can never outlive the
 *      hook.
 *
 *   4. Backgrounded tabs: polling pauses when document.visibilityState
 *      is 'hidden'. On resume, it fires immediately + re-arms the
 *      interval, so a returning user sees fresh data without waiting
 *      a full cycle.
 *
 *   5. Pause on demand: the `enabled` flag makes the polling reactive
 *      to UI state — open a dialog, polling stops; close it, it
 *      resumes from where it left off.
 *
 * Inertia's `preserveState: true + only: [keys]` means the page
 * component instance is kept (state survives) and only the named
 * props are merged in. So the user's `selectedTaskId` / dialog state
 * survive a poll.
 */
export function useBoardLiveSync({
    enabled,
    intervalMs = 8000,
    only = ['tasks'],
}: Options): Result {
    const intervalRef = useRef<number | null>(null);
    const inFlightRef = useRef(false);
    const onlyRef = useRef(only);
    useEffect(() => {
        onlyRef.current = only;
    }, [only]);

    const [lastSyncedAt, setLastSyncedAt] = useState<Date | null>(null);
    const [isSyncing, setIsSyncing] = useState(false);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        if (!enabled) {
            // Stop any in-progress polling; the next time `enabled`
            // flips back on, the effect re-runs and starts fresh.
            return;
        }

        const tick = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            if (inFlightRef.current) {
                return;
            }

            inFlightRef.current = true;
            setIsSyncing(true);

            // Inertia v3 partial reload — `preserveScroll` and
            // `preserveState` are the defaults for partial reloads so
            // we don't pass them. `only` requests the named props
            // only; the page component instance + state survive.
            router.reload({
                only: onlyRef.current,
                onSuccess: () => {
                    setLastSyncedAt(new Date());
                },
                onFinish: () => {
                    inFlightRef.current = false;
                    setIsSyncing(false);
                },
            });
        };

        const start = () => {
            if (intervalRef.current !== null) {
                return;
            }

            intervalRef.current = window.setInterval(tick, intervalMs);
        };

        const stop = () => {
            if (intervalRef.current !== null) {
                window.clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        };

        const onVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                start();
                // Immediate refresh on focus so a user returning to
                // the tab doesn't have to wait for the next interval.
                tick();
            } else {
                stop();
            }
        };

        if (document.visibilityState === 'visible') {
            start();
        }

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            stop();
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
            // Don't reset inFlightRef here — a request that was in
            // flight at unmount will resolve normally and the (now
            // stale) state setters become no-ops thanks to React's
            // unmount handling. Resetting too aggressively could
            // unblock a stale callback we never want to fire.
        };
    }, [enabled, intervalMs]);

    return { lastSyncedAt, isSyncing };
}
