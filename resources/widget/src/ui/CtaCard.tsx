import type { WidgetApi } from '../core/api';
import { t } from '../core/i18n';
import { safeHref } from '../core/safeUrl';
import type { CtaPayload } from '../core/store';
import { store } from '../core/store';

type Props = {
    cta: CtaPayload;
    api: WidgetApi;
    jwt: string;
    accent: string;
};

export function CtaCard({ cta, api, jwt, accent }: Props) {
    const click = () => {
        // Log the click via /events
        api.logEvents(jwt, [
            {
                kind: 'cta.clicked',
                payload: { label: cta.label, kind: cta.kind, url: cta.url },
            },
        ]).catch(() => {
            // best-effort logging
        });

        const safeCtaUrl = safeHref(cta.url ?? null);

        if (safeCtaUrl !== null) {
            window.open(safeCtaUrl, '_blank', 'noopener,noreferrer');
        } else if (
            cta.kind === 'capture_email' ||
            cta.kind === 'demo' ||
            cta.kind === 'signup'
        ) {
            store.set({ leadFormOpen: true });
        }

        store.set({ activeCta: null });
    };

    return (
        <div
            style={{
                padding: '8px 10px',
                border: `1px solid ${accent}33`,
                borderRadius: 10,
                background: `${accent}0a`,
                display: 'flex',
                flexDirection: 'column',
                gap: 6,
                // Tile alongside siblings instead of taking a full row.
                // Buyer feedback (2026-05-15): three stacked cards wasted
                // half the chat panel. Cards now grow to share the row
                // and wrap when copy is long.
                flex: '1 1 140px',
                minWidth: 0,
            }}
        >
            <p
                style={{
                    margin: 0,
                    fontSize: 12,
                    fontWeight: 500,
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                }}
                title={cta.label}
            >
                {cta.label}
            </p>
            <button
                type="button"
                onClick={click}
                style={{
                    background: accent,
                    color: 'white',
                    border: 'none',
                    borderRadius: 8,
                    padding: '6px 10px',
                    fontSize: 12,
                    cursor: 'pointer',
                    alignSelf: 'flex-start',
                }}
            >
                {t('Continue')} →
            </button>
        </div>
    );
}
