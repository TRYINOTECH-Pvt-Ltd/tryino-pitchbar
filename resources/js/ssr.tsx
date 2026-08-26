import { createInertiaApp, router } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { DirectionProvider } from '@radix-ui/react-direction';
import { useEffect, useState } from 'react';
import ReactDOMServer from 'react-dom/server';
import { ConfirmDialogProvider } from '@/components/confirm-dialog-provider';
import { MarketingWidgetMount } from '@/components/marketing-widget-mount';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import AccountLayout from '@/layouts/account-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const defaultAppName = import.meta.env.VITE_APP_NAME || 'Pitchbar';

type CatalogEntry = { rtl?: boolean };
type CatalogShape = Record<string, CatalogEntry>;

type SsrPageProps = {
    branding?: { site_title?: string | null } | null;
    i18n?: {
        locale?: string;
        catalog?: CatalogShape;
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

function titleFor(siteTitle: string, pageTitle: string): string {
    return pageTitle ? `${pageTitle} - ${siteTitle}` : siteTitle;
}

function DirectedApp({
    children,
    initialDir,
}: {
    children: React.ReactNode;
    initialDir: 'ltr' | 'rtl';
}) {
    const [dir, setDir] = useState<'ltr' | 'rtl'>(initialDir);

    useEffect(() => {
        return router.on('navigate', (event) => {
            const i18n = (
                event as CustomEvent<{
                    page?: { props?: { i18n?: SsrPageProps['i18n'] } };
                }>
            ).detail?.page?.props?.i18n;
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
                    <MarketingWidgetMount />
                </ConfirmDialogProvider>
            </TooltipProvider>
        </DirectionProvider>
    );
}

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (pageTitle) => {
            const props = (page.props ?? {}) as SsrPageProps;
            const siteTitle =
                props.branding?.site_title?.trim() || defaultAppName;

            return titleFor(siteTitle, pageTitle);
        },
        resolve: (name) => {
            const pages = import.meta.glob<{ default: ResolvedComponent }>(
                './pages/**/*.tsx',
                { eager: true },
            );
            const key = `./pages/${name}.tsx`;
            const mod = pages[key];

            if (!mod) {
                throw new Error(
                    `Inertia SSR: page component "${name}" not found at ${key}.`,
                );
            }

            return mod;
        },
        layout: (name) => {
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
        // No `strictMode` here — Inertia v3's TS overload for SSR
        // forbids it (it would push us into the "auto" overload which
        // typechecks `el: HTMLElement | null`). React StrictMode for
        // client rendering is opt-in via app.tsx's createInertiaApp.
        //
        // Inertia v3's per-request SSR path uses ONLY `setup` and
        // skips `withApp` entirely (see @inertiajs/react/dist/index.js
        // around line 277). All provider wrapping MUST happen inside
        // `setup`, otherwise the client tree (which DOES apply
        // `withApp`) and the SSR'd HTML diverge and consumers like
        // Radix's Tooltip throw "must be used within TooltipProvider".
        setup: ({ App, props }) => {
            const pageProps = (page.props ?? {}) as SsrPageProps;
            const initialDir = dirFromI18nProps(
                pageProps.i18n?.locale,
                pageProps.i18n?.catalog,
            );

            return (
                <DirectedApp initialDir={initialDir}>
                    <App {...props} />
                </DirectedApp>
            );
        },
    }),
);
