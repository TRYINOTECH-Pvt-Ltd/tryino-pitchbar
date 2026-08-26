import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { useBranding } from '@/hooks/use-branding';
import { useT } from '@/lib/i18n';
import { home } from '@/routes';

import './prism.css';

type Props = {
    title: string;
    description?: string;
    children: ReactNode;
};

/**
 * Prism-themed auth shell. Used by every auth page (login, register,
 * forgot/reset password, 2FA, email verification, invitation accept,
 * password confirmation) when `marketingTheme === 'prism'` so the
 * brand identity carries from the marketing site into the sign-in
 * surface. Mirrors the Harvest AuthSimpleLayout's two-panel layout
 * (brand on the left, form on the right) but uses the Prism gradient
 * + Inter Tight body + Instrument Serif italic accents instead of
 * the olive/cream Harvest palette.
 */
export default function PrismAuthShell({
    title,
    description,
    children,
}: Props) {
    const branding = useBranding();
    const { t } = useT();
    const brandName = branding.site_title || 'Pitchbar';

    // Admin-editable side-panel copy. Buyer-reported (2026-05-26): the
    // bundled "marketing site" phrasing leaked through to workspaces
    // whose product is not a marketing surface. Falls back to neutral
    // copy that points the admin at the editable settings.
    const eyebrow =
        (branding.auth_aside_eyebrow as string | null | undefined) ??
        t('Secure workspace');
    const heading =
        (branding.auth_aside_heading as string | null | undefined) ?? null;
    const lede =
        (branding.auth_aside_lede as string | null | undefined) ?? null;
    const bullets =
        (branding.auth_aside_bullets as string[] | null | undefined) &&
        (branding.auth_aside_bullets as string[]).length > 0
            ? (branding.auth_aside_bullets as string[])
            : [
                  t('2FA, passkeys, and recovery codes'),
                  t('SSO-ready · SOC 2 controls in flight'),
                  t('One workspace, every channel'),
              ];

    return (
        <div className="prism-root prism-auth-shell">
            <link rel="preconnect" href="https://fonts.googleapis.com" />
            <link
                rel="preconnect"
                href="https://fonts.gstatic.com"
                crossOrigin=""
            />
            <link
                rel="stylesheet"
                href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap"
            />

            <aside className="prism-auth-aside">
                <div className="prism-auth-aside-inner">
                    <Link href={home().url} className="logo">
                        <span className="logo-mark" />
                        <span>{brandName}</span>
                    </Link>

                    <div className="prism-auth-hero">
                        <span
                            className="eyebrow"
                            style={{ color: 'rgba(255,255,255,0.85)' }}
                        >
                            <span
                                style={{ background: 'rgba(255,255,255,0.85)' }}
                            />
                            {eyebrow}
                        </span>
                        <h1 className="prism-auth-display">
                            {heading ?? t('Welcome back to the conversations.')}
                        </h1>
                        <p className="prism-auth-lede">
                            {lede ??
                                t(
                                    'Your admin can customise this copy under Settings → Branding so the auth screen reads in your brand voice.',
                                )}
                        </p>

                        <ul className="prism-auth-bullets">
                            {bullets.map((b, i) => (
                                <li key={i}>
                                    <span className="ic">✓</span>
                                    <span>{b}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <Link href={home().url} className="prism-auth-back">
                        ← {t('Back to site')}
                    </Link>
                </div>
            </aside>

            <main className="prism-auth-main">
                <header className="prism-auth-mobile-bar">
                    <Link href={home().url} className="logo">
                        <span className="logo-mark" />
                        <span>{brandName}</span>
                    </Link>
                    <Link href={home().url} className="prism-auth-back">
                        ← {t('Site')}
                    </Link>
                </header>

                <section className="prism-auth-form-wrap">
                    <div className="prism-auth-form-card">
                        <div className="prism-auth-form-head">
                            <span className="eyebrow">{t('Account access')}</span>
                            <h2 className="prism-auth-title">{t(title)}</h2>
                            {description ? (
                                <p className="prism-auth-desc">
                                    {t(description)}
                                </p>
                            ) : null}
                        </div>
                        <div className="prism-auth-form-body">{children}</div>
                    </div>
                </section>
            </main>
        </div>
    );
}
