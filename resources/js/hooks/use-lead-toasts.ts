import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

type LeadFeedItem = {
    id: string;
    email: string | null;
    name: string | null;
    phone: string | null;
    agent_name: string;
    inbox_url: string;
    created_at: string | null;
};

type HandoffRequest = {
    id: string;
    agent_name: string;
    page_url: string | null;
    preview: string | null;
    conversation_url: string;
    requested_at: string | null;
};

type LeadFeedResponse = {
    data: {
        leads: LeadFeedItem[];
        handoff_requests: HandoffRequest[];
        count_24h: number;
        now: string;
    };
};

const POLL_INTERVAL_MS = 30000;
const STORAGE_KEY = 'pitchbar:leads:since';
const HANDOFF_STORAGE_KEY = 'pitchbar:handoff:since';

function readSinceFor(key: string): string {
    if (typeof window === 'undefined') {
        return '';
    }

    try {
        return window.sessionStorage.getItem(key) ?? '';
    } catch {
        return '';
    }
}

function writeSinceFor(key: string, value: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(key, value);
    } catch {
        // private mode / quota — silently ignore
    }
}

function fireBrowserNotification(lead: LeadFeedItem): void {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return;
    }

    if (Notification.permission !== 'granted') {
        return;
    }

    try {
        const headline = lead.email ?? lead.phone ?? 'New lead';
        const body = `${lead.agent_name} captured ${headline}`;
        const note = new Notification('New lead captured', {
            body,
            tag: `lead-${lead.id}`,
        });
        note.onclick = () => {
            window.focus();
            router.visit(lead.inbox_url);
        };
    } catch {
        // Some browsers (Safari with strict policies) throw — ignore.
    }
}

function fireHandoffNotification(item: HandoffRequest): void {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return;
    }

    if (Notification.permission !== 'granted') {
        return;
    }

    try {
        const body = item.preview
            ? `"${item.preview}"`
            : `Visitor on ${item.agent_name} wants a human.`;
        const note = new Notification('🔔 Visitor wants a human', {
            body,
            // Tag dedupes — if a second poll picks up the same
            // handoff before the operator clicks, the OS replaces
            // the prior notification rather than stacking duplicates.
            tag: `handoff-${item.id}`,
            requireInteraction: true,
        });
        note.onclick = () => {
            window.focus();
            router.visit(item.conversation_url);
        };
    } catch {
        // Same Safari caveat as the lead path.
    }
}

/**
 * Polls /app/leads/feed every 30 seconds; fires a sonner toast +
 * (when the user has granted Notification permission) a native
 * browser notification on each new lead since the previous poll.
 *
 * The "since" cursor lives in sessionStorage so closing and reopening
 * the tab won't re-toast already-seen leads. First mount initialises
 * the cursor to "now" — historical leads never trigger toasts.
 *
 * Polling pauses on hidden tabs so a backgrounded dashboard doesn't
 * burn HTTP for nothing.
 */
export function useLeadToasts(enabled: boolean): void {
    const isMounted = useRef(false);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        if (typeof window === 'undefined') {
            return;
        }

        isMounted.current = true;

        // Seed both cursors on first ever mount so existing leads +
        // existing in-flight handoff requests don't explode into a
        // wall of toasts.
        if (readSinceFor(STORAGE_KEY) === '') {
            writeSinceFor(STORAGE_KEY, new Date().toISOString());
        }

        if (readSinceFor(HANDOFF_STORAGE_KEY) === '') {
            writeSinceFor(HANDOFF_STORAGE_KEY, new Date().toISOString());
        }

        let timer: number | null = null;
        let aborter: AbortController | null = null;

        const poll = async () => {
            if (!isMounted.current) {
                return;
            }

            if (document.visibilityState === 'hidden') {
                return;
            }

            const since = readSinceFor(STORAGE_KEY);
            const handoffSince = readSinceFor(HANDOFF_STORAGE_KEY);

            try {
                aborter = new AbortController();
                const params = new URLSearchParams();

                if (since) {
                    params.set('since', since);
                }

                if (handoffSince) {
                    params.set('handoff_since', handoffSince);
                }

                const url =
                    '/app/leads/feed' +
                    (params.toString() ? `?${params.toString()}` : '');
                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: aborter.signal,
                });

                if (!res.ok) {
                    return;
                }

                const json = (await res.json()) as LeadFeedResponse;
                const leads = json.data?.leads ?? [];
                const handoffRequests = json.data?.handoff_requests ?? [];

                if (leads.length > 0) {
                    // Toast oldest-first so multiple new leads stack
                    // visibly in arrival order.
                    const ordered = [...leads].reverse();
                    ordered.forEach((lead) => {
                        const headline = lead.email ?? lead.name ?? 'New lead';
                        toast.success(`New lead — ${headline}`, {
                            description: `Captured by ${lead.agent_name}`,
                            action: {
                                label: 'Open',
                                onClick: () => router.visit(lead.inbox_url),
                            },
                        });
                        fireBrowserNotification(lead);
                    });
                    const newest = leads[0];

                    if (newest?.created_at) {
                        writeSinceFor(STORAGE_KEY, newest.created_at);
                    }
                }

                if (handoffRequests.length > 0) {
                    const orderedHandoff = [...handoffRequests].reverse();
                    orderedHandoff.forEach((item) => {
                        toast.warning('🔔 Visitor wants a human', {
                            description:
                                item.preview ?? `On ${item.agent_name}`,
                            duration: 12000,
                            action: {
                                label: 'Claim',
                                onClick: () =>
                                    router.visit(item.conversation_url),
                            },
                        });
                        fireHandoffNotification(item);
                    });
                    const newestHandoff = handoffRequests[0];

                    if (newestHandoff?.requested_at) {
                        writeSinceFor(
                            HANDOFF_STORAGE_KEY,
                            newestHandoff.requested_at,
                        );
                    }
                }
            } catch {
                // Network blip / aborted on unmount — silent retry next tick.
            }
        };

        const start = () => {
            poll();
            timer = window.setInterval(poll, POLL_INTERVAL_MS);
        };

        const stop = () => {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }

            aborter?.abort();
        };

        const onVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                if (timer === null) {
                    start();
                }
            } else {
                stop();
            }
        };

        if (document.visibilityState === 'visible') {
            start();
        }

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            isMounted.current = false;
            stop();
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        };
    }, [enabled]);
}

/**
 * One-off helper for the "Enable browser notifications" button. Returns
 * the resulting permission so callers can update their UI accordingly.
 */
export async function requestLeadNotificationsPermission(): Promise<NotificationPermission> {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return 'denied';
    }

    if (
        Notification.permission === 'granted' ||
        Notification.permission === 'denied'
    ) {
        return Notification.permission;
    }

    try {
        return await Notification.requestPermission();
    } catch {
        return 'denied';
    }
}
