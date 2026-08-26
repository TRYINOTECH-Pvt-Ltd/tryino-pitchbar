import { Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import BrandLockup from '@/components/brand-lockup';
import { DirArrow } from '@/components/dir-icon';
import { LocaleSuggestionBanner } from '@/components/locale-suggestion-banner';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { Button } from '@/components/ui/button';
import { useBranding } from '@/hooks/use-branding';
import { cn } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';

/**
 * Footer link router. External URLs (anything starting with http://,
 * https://, or mailto:/tel:) render as a plain <a target="_blank"
 * rel="noopener noreferrer"> so the browser handles them normally —
 * Inertia <Link> would XHR-fetch the URL expecting a JSON Inertia
 * response and pop its error overlay on the HTML it gets back instead
 * (the "blank modal" buyer reported 2026-05-21 for the X icon). Empty
 * or '#' hrefs render nothing — better than a dead button. Everything
 * else stays an Inertia <Link>.
 */
function FooterLink({
    href,
    children,
    className,
}: {
    href: string;
    children: ReactNode;
    className?: string;
}) {
    const trimmed = (href ?? '').trim();

    if (trimmed === '' || trimmed === '#') {
        return null;
    }

    const isExternal = /^(https?:|mailto:|tel:)/i.test(trimmed);

    if (isExternal) {
        return (
            <a
                href={trimmed}
                target="_blank"
                rel="noopener noreferrer"
                className={className}
            >
                {children}
            </a>
        );
    }

    return (
        <Link href={trimmed} className={className}>
            {children}
        </Link>
    );
}

/**
 * Shared header + footer for every marketing page (home, pricing,
 * how-it-works, privacy, terms). Keeps the navbar identical across the
 * SPA so visitors don't see chrome jump when clicking between pages.
 *
 * Pages render their own content as children. The nav_items / footer
 * blocks come from the controller as a `marketing` Inertia prop so
 * MarketingHomeContent stays the single source of truth.
 */

type ContentLink = {
    label: string;
    href: string;
};

export type MarketingShellContent = {
    nav_items: ContentLink[];
    header: {
        resources_label: string;
        resources_href: string;
    };
    footer: {
        brand_description: string;
        socials: ContentLink[];
        groups: Array<{
            title: string;
            links: ContentLink[];
        }>;
        legal_title: string;
        legal_links: ContentLink[];
        copyright: string;
    };
};

type AuthProps = {
    auth: { user?: { id: number | string } | null };
    canRegister?: boolean;
    demo?: boolean;
};

const pineButtonClass =
    'bg-[#173f2c] text-[#f7f4ec] hover:bg-[#123322] hover:text-[#f7f4ec]';

function LogoMark({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'flex items-center justify-center text-[#173f2c]',
                className,
            )}
        >
            <AppLogoIcon className="size-full" />
        </span>
    );
}

function BrandWordmark({
    logoUrl,
    logoDarkUrl,
    siteTitle,
    mode,
    size = 'header',
}: {
    logoUrl: string | null;
    logoDarkUrl?: string | null;
    siteTitle: string;
    mode: 'logo_text' | 'logo_only' | 'text_only';
    size?: 'header' | 'footer';
}) {
    const fallbackMarkSize = size === 'header' ? 'size-8' : 'size-7';
    const imageClassName =
        size === 'header'
            ? 'h-10 w-auto max-w-[220px] object-contain'
            : 'h-9 w-auto max-w-[180px] object-contain';
    const titleClassName =
        size === 'header'
            ? 'text-2xl font-semibold tracking-normal text-[#263129]'
            : 'text-xl font-semibold text-[#263129]';

    return (
        <Link href={home()} className="flex items-center gap-2.5">
            <BrandLockup
                siteTitle={siteTitle}
                logoUrl={logoUrl}
                logoDarkUrl={logoDarkUrl}
                mode={mode}
                className="gap-2.5"
                logoClassName={imageClassName}
                textClassName={titleClassName}
                fallbackLogo={<LogoMark className={fallbackMarkSize} />}
            />
        </Link>
    );
}

export function MarketingShell({
    content,
    children,
}: {
    content: MarketingShellContent;
    children: ReactNode;
}) {
    const branding = useBranding();
    const { auth, canRegister, demo } = usePage<AuthProps>().props;
    const hasUser = Boolean(auth.user);
    const siteTitle = branding.site_title || 'Pitchbar';

    const headerPrimaryLabel = hasUser ? 'Dashboard' : 'Get started';
    const headerPrimaryHref = hasUser
        ? dashboard()
        : canRegister
          ? register()
          : login();

    // Demo-mode: open auth links in a new tab so the reviewer (CodeCanyon
    // buyer poking around the live preview) keeps the marketing page
    // visible while they jump into the app. Auth-already case is a
    // signed-in admin who's clearly past the demo flow — keep the
    // dashboard link as a normal in-tab navigation.
    const demoOpenInNewTab = Boolean(demo) && !hasUser;
    const newTabProps = demoOpenInNewTab
        ? { target: '_blank' as const, rel: 'noopener noreferrer' }
        : {};

    // Mobile nav: the desktop <nav> is lg:flex / hidden below lg, so
    // without this phones + tablets get no way to reach the marketing
    // pages. Toggled by the hamburger; closes on link click + Escape.
    const [mobileOpen, setMobileOpen] = useState(false);
    useEffect(() => {
        if (!mobileOpen) {
            return;
        }

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setMobileOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [mobileOpen]);

    return (
        <div className="min-h-screen overflow-x-hidden bg-[#f7f2e8] text-[#252a24]">
            <LocaleSuggestionBanner />
            <header className="mx-auto flex max-w-[1340px] items-center justify-between gap-5 px-6 py-7 lg:px-10">
                <BrandWordmark
                    logoUrl={branding.header_logo_url}
                    logoDarkUrl={branding.header_logo_dark_url}
                    siteTitle={siteTitle}
                    mode={branding.header_brand_display}
                />

                <nav className="hidden items-center gap-10 text-sm font-semibold text-[#41483f] lg:flex">
                    {content.nav_items.map((item) => (
                        <Link
                            key={`${item.label}-${item.href}`}
                            href={item.href}
                            className="transition hover:text-[#173f2c]"
                        >
                            {item.label}
                        </Link>
                    ))}
                    {demo && content.header.resources_label ? (
                        // Documentation link is demo-install-only — buyers
                        // running self-hosted CodeCanyon installs don't want
                        // a public docs link on their landing nav. Toggled
                        // by DEMO=true in the install's .env.
                        //
                        // Open in a new tab on purpose:
                        //   1. The docs site is a separate Blade-rendered
                        //      experience (Mintlify-style sidebar + chrome),
                        //      so an Inertia <Link> would trigger the modal-
                        //      overlay fallback we hit before.
                        //   2. Visitors who pop the docs to skim shouldn't
                        //      lose their place on the marketing flow.
                        <a
                            href={content.header.resources_href}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 transition hover:text-[#173f2c]"
                        >
                            {content.header.resources_label}
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                className="size-3 opacity-70"
                                aria-hidden="true"
                            >
                                <path d="M7 17L17 7M9 7h8v8" />
                            </svg>
                        </a>
                    ) : null}
                </nav>

                <div className="flex items-center gap-1">
                    {/*
                        Locale picker — opens the same searchable modal
                        as the admin shell. The "marketing" variant
                        renders a transparent ghost pill that matches
                        the Login link's styling so it sits in the
                        marketing chrome without the dark admin token
                        background. Click → modal lists every locale
                        we ship.
                    */}
                    <LocaleSwitcher variant="marketing" />
                    {!hasUser && (
                        // Ghost button — same height as the primary CTA so
                        // the pair reads as one unit; transparent base, soft
                        // green wash on hover so it feels clickable without
                        // competing with Get started.
                        <Link
                            href={login()}
                            {...newTabProps}
                            className="hidden h-10 items-center rounded-md px-4 text-sm font-semibold text-[#41483f] transition hover:bg-[#173f2c]/8 hover:text-[#173f2c] sm:inline-flex"
                        >
                            Login
                        </Link>
                    )}
                    <Button
                        className={cn(
                            'h-10 rounded-md px-4 text-sm font-semibold',
                            pineButtonClass,
                        )}
                        asChild
                    >
                        <Link
                            href={headerPrimaryHref}
                            {...newTabProps}
                            className="inline-flex items-center gap-1.5"
                        >
                            {headerPrimaryLabel}
                            <DirArrow direction="forward" className="size-4" />
                        </Link>
                    </Button>

                    {/* Hamburger — only below lg, where the desktop nav is
                        hidden. Reveals the marketing links in a panel. */}
                    <button
                        type="button"
                        onClick={() => setMobileOpen((open) => !open)}
                        aria-expanded={mobileOpen}
                        aria-controls="marketing-mobile-nav"
                        aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                        className="inline-flex size-10 items-center justify-center rounded-md text-[#41483f] transition hover:bg-[#173f2c]/8 hover:text-[#173f2c] lg:hidden"
                    >
                        {mobileOpen ? (
                            <X className="size-5" />
                        ) : (
                            <Menu className="size-5" />
                        )}
                    </button>
                </div>
            </header>

            {/* Mobile / tablet nav panel. */}
            {mobileOpen && (
                <nav
                    id="marketing-mobile-nav"
                    className="mx-auto max-w-[1340px] px-6 pb-4 lg:hidden"
                >
                    <div className="flex flex-col gap-1 rounded-xl border border-[#e4dccc] bg-white/80 p-2 text-sm font-semibold text-[#41483f] shadow-sm">
                        {content.nav_items.map((item) => (
                            <Link
                                key={`m-${item.label}-${item.href}`}
                                href={item.href}
                                onClick={() => setMobileOpen(false)}
                                className="rounded-lg px-3 py-2.5 transition hover:bg-[#173f2c]/8 hover:text-[#173f2c]"
                            >
                                {item.label}
                            </Link>
                        ))}
                        {demo && content.header.resources_label ? (
                            <a
                                href={content.header.resources_href}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={() => setMobileOpen(false)}
                                className="rounded-lg px-3 py-2.5 transition hover:bg-[#173f2c]/8 hover:text-[#173f2c]"
                            >
                                {content.header.resources_label}
                            </a>
                        ) : null}
                        {!hasUser && (
                            <Link
                                href={login()}
                                {...newTabProps}
                                onClick={() => setMobileOpen(false)}
                                className="rounded-lg px-3 py-2.5 transition hover:bg-[#173f2c]/8 hover:text-[#173f2c] sm:hidden"
                            >
                                Login
                            </Link>
                        )}
                    </div>
                </nav>
            )}

            <main>{children}</main>

            <footer className="mx-auto grid max-w-[1200px] gap-8 px-6 pt-2 pb-9 lg:grid-cols-[1.5fr_2fr_0.8fr] lg:px-10">
                <div className="flex flex-col gap-4">
                    <BrandWordmark
                        logoUrl={
                            branding.footer_logo_url ?? branding.header_logo_url
                        }
                        logoDarkUrl={
                            branding.footer_logo_dark_url ??
                            branding.header_logo_dark_url
                        }
                        siteTitle={siteTitle}
                        mode={branding.footer_brand_display}
                        size="footer"
                    />
                    <p className="max-w-[260px] text-xs leading-5 font-medium text-[#777d73]">
                        {content.footer.brand_description}
                    </p>
                    <div className="flex gap-5 text-sm font-bold text-[#667064]">
                        {content.footer.socials.map((item) => (
                            <FooterLink
                                key={`${item.label}-${item.href}`}
                                href={item.href}
                            >
                                {item.label}
                            </FooterLink>
                        ))}
                    </div>
                </div>

                <div className="grid gap-8 sm:grid-cols-3">
                    {content.footer.groups.map((group) => (
                        <div key={group.title}>
                            <h3 className="text-xs font-bold text-[#30362f]">
                                {group.title}
                            </h3>
                            <div className="mt-3 flex flex-col gap-2 text-xs font-medium text-[#777d73]">
                                {group.links.map((item) => (
                                    <FooterLink
                                        key={`${group.title}-${item.label}-${item.href}`}
                                        href={item.href}
                                    >
                                        {item.label}
                                    </FooterLink>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                <div>
                    <h3 className="text-xs font-bold text-[#30362f]">
                        {content.footer.legal_title}
                    </h3>
                    <div className="mt-3 flex flex-col gap-2 text-xs font-medium text-[#777d73]">
                        {content.footer.legal_links.map((item) => (
                            <FooterLink
                                key={`${item.label}-${item.href}`}
                                href={item.href}
                            >
                                {item.label}
                            </FooterLink>
                        ))}
                    </div>
                </div>

                <div className="text-xs font-medium text-[#92978d] lg:col-span-3 lg:text-center">
                    {content.footer.copyright}
                </div>
            </footer>
        </div>
    );
}
