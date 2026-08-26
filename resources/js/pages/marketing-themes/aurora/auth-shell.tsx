import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { useBranding } from '@/hooks/use-branding';
import { useT } from '@/lib/i18n';
import { home } from '@/routes';

import './aurora.css';

type Props = {
    title: string;
    description?: string;
    children: ReactNode;
};

/**
 * Aurora-themed auth shell. Used by every auth page (login, register,
 * forgot/reset password, 2FA, email verification, invitation accept,
 * password confirmation) when `marketingTheme === 'aurora'` so the
 * brand identity carries from the marketing site into the sign-in
 * surface. Editorial paper/ink palette with the lime signal accent
 * Aurora uses on the marketing site.
 */
export default function AuroraAuthShell({
    title,
    description,
    children,
}: Props) {
    const branding = useBranding();
    const { t } = useT();
    const brandName = branding.site_title || 'Pitchbar';

    // Admin-editable side-panel copy. Buyer-reported (2026-05-26): the
    // bundled "marketing site" phrasing leaked through to workspaces
    // whose product is not a marketing surface. Falls back to the
    // theme's defaults so first-install Aurora still ships pre-styled.
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
        <div className="aurora-root aurora-auth-shell theme-light font-grotesk">
            <link rel="preconnect" href="https://fonts.googleapis.com" />
            <link
                rel="preconnect"
                href="https://fonts.gstatic.com"
                crossOrigin=""
            />
            <link
                rel="stylesheet"
                href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;500&display=swap"
            />

            <aside className="aurora-auth-aside">
                <div className="aurora-auth-aside-inner">
                    <Link href={home().url} className="aurora-auth-logo">
                        <span className="brand-mark" />
                        <span>{brandName.toUpperCase()}</span>
                    </Link>

                    <div className="aurora-auth-hero">
                        <span className="eyebrow">
                            <span className="dot" />
                            <span className="idx">A/01</span>
                            {eyebrow}
                        </span>
                        <h1 className="aurora-auth-display">
                            {heading ?? t('Sign in to the signal.')}
                        </h1>
                        <p className="aurora-auth-lede">
                            {lede ??
                                t(
                                    'Your admin can customise this copy under Settings → Branding so the auth screen reads in your brand voice.',
                                )}
                        </p>

                        <ul className="aurora-auth-bullets">
                            {bullets.map((b, i) => (
                                <li key={i}>
                                    <span className="pip" />
                                    <span>{b}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="aurora-auth-foot">
                        <Link href={home().url} className="aurora-auth-back">
                            ← {t('Back to site')}
                        </Link>
                        <span className="aurora-auth-foot-meta">
                            {t('ALL SYSTEMS LIVE')}
                        </span>
                    </div>
                </div>
            </aside>

            <main className="aurora-auth-main">
                <header className="aurora-auth-mobile-bar">
                    <Link href={home().url} className="aurora-auth-logo">
                        <span className="brand-mark" />
                        <span>{brandName.toUpperCase()}</span>
                    </Link>
                    <Link href={home().url} className="aurora-auth-back">
                        ← {t('Site')}
                    </Link>
                </header>

                <section className="aurora-auth-form-wrap">
                    <div className="aurora-auth-form-card">
                        <div className="aurora-auth-form-head">
                            <span className="eyebrow">
                                <span className="dot" />
                                {t('Account access')}
                            </span>
                            <h2 className="aurora-auth-title">{t(title)}</h2>
                            {description ? (
                                <p className="aurora-auth-desc">
                                    {t(description)}
                                </p>
                            ) : null}
                        </div>
                        <div className="aurora-auth-form-body">{children}</div>
                    </div>
                </section>
            </main>
        </div>
    );
}
