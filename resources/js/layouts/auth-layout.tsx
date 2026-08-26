import { usePage } from '@inertiajs/react';

import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import AuroraAuthShell from '@/pages/marketing-themes/aurora/auth-shell';
import PrismAuthShell from '@/pages/marketing-themes/prism/auth-shell';

type SharedProps = {
    marketingTheme?: string;
};

/**
 * Auth layout dispatcher. Picks the per-theme auth shell so the
 * login / register / reset flows match the active marketing theme.
 * Falls back to the Harvest two-panel layout when the active theme
 * doesn't ship its own shell. Client report 2026-05-23: "when we
 * change theme, all the frontend pages should follow the theme
 * style — login page remains the default theme".
 */
export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const { marketingTheme } = usePage<SharedProps>().props;

    if (marketingTheme === 'prism') {
        return (
            <PrismAuthShell title={title} description={description}>
                {children}
            </PrismAuthShell>
        );
    }

    if (marketingTheme === 'aurora') {
        return (
            <AuroraAuthShell title={title} description={description}>
                {children}
            </AuroraAuthShell>
        );
    }

    return (
        <AuthLayoutTemplate title={title} description={description}>
            {children}
        </AuthLayoutTemplate>
    );
}
