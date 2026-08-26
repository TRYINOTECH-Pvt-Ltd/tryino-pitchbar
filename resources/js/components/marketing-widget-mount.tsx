import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

declare global {
    interface Window {
        Pitchbar?: {
            mount: (host?: HTMLElement) => void;
            unmount?: () => void;
        };
    }
}

type MarketingWidgetProps = {
    enabled: boolean;
    agent_id: string | null;
    is_demo: boolean;
    version: string;
    src: string;
};

const SCRIPT_MARKER = 'data-marketing-widget';

/**
 * Reactively mounts / unmounts the marketing-site widget based on the
 * `marketingWidget` Inertia shared prop. Without this, the Blade-emitted
 * `<script>` tag only re-evaluates on a full HTTP reload — so when an
 * operator toggled the widget off in /settings/system, the widget
 * stayed visible on the marketing tab until Ctrl+R (client report
 * 2026-05-25).
 *
 * Lives OUTSIDE the Inertia component (under DirectedApp in app.tsx),
 * so it reads page props by hand from the Inertia dataset on mount
 * and via `router.on('navigate')` afterwards — `usePage()` is only
 * valid INSIDE the Inertia tree.
 *
 * Three trigger paths:
 *   1. Initial mount — reads `#app[data-page]`.
 *   2. Same-tab navigation — `router.on('navigate')` swaps the prop
 *      and the useEffect tears down / re-mounts.
 *   3. Cross-tab — `visibilitychange` does a partial reload of just
 *      the `marketingWidget` shared prop so a background marketing
 *      tab picks up admin-side toggles without an Inertia visit.
 */
export function MarketingWidgetMount() {
    const [widget, setWidget] = useState<MarketingWidgetProps | undefined>(
        readWidgetFromDom,
    );

    useEffect(() => {
        // `success` fires after EVERY Inertia response — full visits
        // AND partial reloads (e.g. `router.reload({ only: [...] })`).
        // `navigate` only fires on URL changes, so it misses partial
        // reloads, which is exactly the path the cross-tab
        // visibilitychange handler below depends on.
        const off = router.on('success', (event) => {
            const detail = (
                event as CustomEvent<{
                    page?: {
                        props?: { marketingWidget?: MarketingWidgetProps };
                    };
                }>
            ).detail;
            const next = detail?.page?.props?.marketingWidget;

            // Partial reloads only carry the requested props; if the
            // server didn't send `marketingWidget` this round, keep the
            // previous value so we don't accidentally tear down on an
            // unrelated reload.
            if (next !== undefined) {
                setWidget(next);
            }
        });

        return () => {
            off();
        };
    }, []);

    useEffect(() => {
        if (widget === undefined) {
            return;
        }

        const desiredAgentId = widget.enabled ? widget.agent_id : null;
        const currentScript = document.querySelector<HTMLScriptElement>(
            `script[${SCRIPT_MARKER}]`,
        );
        const currentAgentId =
            currentScript?.getAttribute('data-agent-id') ?? null;

        // Already in the desired state — first-paint case, where Blade
        // emitted the correct script tag matching the shared prop.
        if (currentAgentId === desiredAgentId) {
            return;
        }

        // State has drifted. Tear down whatever's there, then inject
        // the desired tag (or nothing if disabled).
        teardown(currentScript);

        if (desiredAgentId !== null) {
            injectScript({
                src: `${widget.src}?v=${widget.version}`,
                agentId: desiredAgentId,
                demo: widget.is_demo,
            });
        }
    }, [widget]);

    const isMarketingSurface = widget !== undefined;

    // Cross-tab sync: when the marketing tab regains focus, partial-
    // reload just the `marketingWidget` shared prop so the operator's
    // admin-side toggle takes effect without an Inertia visit. The
    // useEffect above then reacts to the refreshed prop.
    useEffect(() => {
        if (!isMarketingSurface) {
            return;
        }

        const handleVisibility = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['marketingWidget'] });
            }
        };

        document.addEventListener('visibilitychange', handleVisibility);

        return () => {
            document.removeEventListener('visibilitychange', handleVisibility);
        };
    }, [isMarketingSurface]);

    return null;
}

function readWidgetFromDom(): MarketingWidgetProps | undefined {
    if (typeof document === 'undefined') {
        return undefined;
    }

    // Inertia v3 inlines the initial page payload as the JSON body of
    // `<script type="application/json" data-page="...">`. The
    // attribute holds the mount element id, NOT the payload.
    const payloadScript = document.querySelector<HTMLScriptElement>(
        'script[type="application/json"][data-page]',
    );
    const raw = payloadScript?.textContent;

    if (!raw) {
        return undefined;
    }

    try {
        const parsed = JSON.parse(raw) as {
            props?: { marketingWidget?: MarketingWidgetProps };
        };

        return parsed.props?.marketingWidget;
    } catch {
        return undefined;
    }
}

function teardown(script: HTMLScriptElement | null) {
    if (script !== null) {
        script.remove();
    }

    // Drop any orphan #pitchbar-root nodes from prior bundles + ask
    // the live bundle to dispose its React tree if it can.
    if (typeof window !== 'undefined' && window.Pitchbar?.unmount) {
        try {
            window.Pitchbar.unmount();
        } catch {
            // Older bundles without unmount() — fall back to manual remove.
        }
    }

    document
        .querySelectorAll('#pitchbar-root')
        .forEach((node) => node.remove());
}

function injectScript({
    src,
    agentId,
    demo,
}: {
    src: string;
    agentId: string;
    demo: boolean;
}) {
    const tag = document.createElement('script');
    tag.src = src;
    tag.defer = true;
    tag.setAttribute('data-agent-id', agentId);
    tag.setAttribute(SCRIPT_MARKER, 'true');

    if (demo) {
        tag.setAttribute('data-demo', 'true');
    }

    document.body.appendChild(tag);
}
