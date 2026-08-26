import { createInertiaApp, router } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import { useEffect, useState } from 'react';
import { ConfirmDialogProvider } from '@/components/confirm-dialog-provider';
import { MarketingWidgetMount } from '@/components/marketing-widget-mount';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AccountLayout from '@/layouts/account-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { registerPwa } from '@/pwa-register';
import type { Branding } from '@/types/branding';

// D1: install the admin shell service worker as early as possible so
// the first page load already populates the cache. The helper guards
// on protocol + admin path so marketing / auth surfaces stay
// uninstrumented.
registerPwa();

const defaultAppName = import.meta.env.VITE_APP_NAME || 'Pitchbar';

function currentSiteTitle(): string {
    if (typeof window === 'undefined') {
        return defaultAppName;
    }

    return (
        window.__PITCHBAR_SITE_TITLE__ ??
        document
            .querySelector('meta[name="application-name"]')
            ?.getAttribute('content') ??
        defaultAppName
    );
}

function upsertHeadLink(id: string, rel: string, href: string): void {
    let link = document.getElementById(id) as HTMLLinkElement | null;

    if (link === null) {
        link = document.createElement('link');
        link.id = id;
        document.head.appendChild(link);
    }

    link.rel = rel;
    link.href = href;
}

function syncBranding(branding?: Branding): void {
    if (typeof window === 'undefined') {
        return;
    }

    const previousTitle = currentSiteTitle();
    const nextSiteTitle = branding?.site_title?.trim() || previousTitle;

    window.__PITCHBAR_SITE_TITLE__ = nextSiteTitle;

    const meta = document.querySelector('meta[name="application-name"]');

    if (meta !== null) {
        meta.setAttribute('content', nextSiteTitle);
    }

    if (document.title === previousTitle) {
        document.title = nextSiteTitle;
    } else if (document.title.endsWith(` - ${previousTitle}`)) {
        document.title = `${document.title.slice(0, -` - ${previousTitle}`.length)} - ${nextSiteTitle}`;
    }

    const faviconUrl =
        branding?.favicon_url ??
        window.__PITCHBAR_DEFAULT_FAVICON_URL__ ??
        '/favicon.ico';
    const touchIconUrl =
        branding?.favicon_url ??
        window.__PITCHBAR_DEFAULT_TOUCH_ICON_URL__ ??
        '/apple-touch-icon.png';

    window.__PITCHBAR_FAVICON_URL__ = faviconUrl;
    upsertHeadLink('app-favicon', 'icon', faviconUrl);
    upsertHeadLink('app-apple-touch-icon', 'apple-touch-icon', touchIconUrl);
}

if (typeof window !== 'undefined') {
    router.on('navigate', (event) => {
        const page = (
            event as CustomEvent<{ page?: { props?: { branding?: Branding } } }>
        ).detail?.page;

        syncBranding(page?.props?.branding);
    });
}

createInertiaApp({
    title: (title) => {
        const siteTitle = currentSiteTitle();

        return title ? `${title} - ${siteTitle}` : siteTitle;
    },
    layout: (name) => {
        // Pages under app/, admin/, onboarding/ all explicitly wrap their
        // own children in <AppLayout> (or AdminLayout). If we auto-apply
        // here too they get double-wrapped — which manifests as an empty
        // ghost sidebar column between the real sidebar and the content.
        // Settings pages are the only ones that nest layouts, so they
        // get the auto-applied [AccountLayout, SettingsLayout] pair.
        // AccountLayout picks the AdminLayout sidebar for super_admins
        // and AppLayout for customers, so /settings/* always shows the
        // sidebar that matches who's logged in.
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AccountLayout, SettingsLayout];
            case name === 'dashboard':
                return AppLayout;
            default:
                return null;
        }
    },
    strictMode: true,
    withApp(app) {
        // DirectionProvider feeds Radix primitives (DropdownMenu,
        // Popover, Tooltip, Select, Sheet…) the writing direction so
        // their alignment, animations, and keyboard navigation flip
        // automatically for RTL locales. The wrapper sits OUTSIDE the
        // Inertia tree (so usePage() isn't reachable) — instead it
        // reads the initial dir from the server-rendered <html dir>
        // attribute and listens to router 'navigate' events to flip on
        // locale switch.
        return <DirectedApp>{app}</DirectedApp>;
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

function readHtmlDir(): 'ltr' | 'rtl' {
    if (typeof document === 'undefined') {
        return 'ltr';
    }

    const dir = document.documentElement.getAttribute('dir');

    return dir === 'rtl' ? 'rtl' : 'ltr';
}

type CatalogEntry = { rtl?: boolean };
type CatalogShape = Record<string, CatalogEntry>;

type NavigatePagePayload = {
    page?: {
        props?: {
            i18n?: {
                locale?: string;
                catalog?: CatalogShape;
            };
        };
    };
};

function dirFromI18nProps(
    locale: string | undefined,
    catalog: CatalogShape | undefined,
): 'ltr' | 'rtl' {
    if (!locale || !catalog) {
        return 'ltr';
    }

    return catalog[locale]?.rtl ? 'rtl' : 'ltr';
}

function DirectedApp({ children }: { children: React.ReactNode }) {
    const [dir, setDir] = useState<'ltr' | 'rtl'>(() => readHtmlDir());

    useEffect(() => {
        // Inertia visits swap only the page component, not the Blade
        // root — so a locale switch doesn't re-render <html dir>. Listen
        // for navigate events, look up the new locale's RTL flag in the
        // shared catalog, and update both the DOM root + the React
        // DirectionProvider so Radix primitives stay in sync.
        return router.on('navigate', (event) => {
            const i18n = (event as CustomEvent<NavigatePagePayload>).detail
                ?.page?.props?.i18n;
            const next = dirFromI18nProps(i18n?.locale, i18n?.catalog);

            if (typeof document !== 'undefined') {
                document.documentElement.setAttribute('dir', next);
            }

            setDir((prev) => (prev === next ? prev : next));
        });
    }, []);

    return (
        <DirectionProvider dir={dir}>
            <TooltipProvider delayDuration={0}>
                <ConfirmDialogProvider>
                    {children}
                    <Toaster />
                    {/* Reactive marketing-widget script tag controller.
                        Renders nothing; manages document.body script tag
                        based on the `marketingWidget` Inertia shared prop. */}
                    <MarketingWidgetMount />
                </ConfirmDialogProvider>
            </TooltipProvider>
        </DirectionProvider>
    );
}
