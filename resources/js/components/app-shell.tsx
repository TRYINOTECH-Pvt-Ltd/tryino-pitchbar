import { usePage } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
    sidebarWidth?: string;
};

export function AppShell({
    children,
    variant = 'sidebar',
    sidebarWidth,
}: Props) {
    const isOpen = usePage().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    const style = sidebarWidth
        ? ({ '--sidebar-width': sidebarWidth } as CSSProperties)
        : undefined;

    return (
        <SidebarProvider defaultOpen={isOpen} style={style}>
            {children}
        </SidebarProvider>
    );
}
