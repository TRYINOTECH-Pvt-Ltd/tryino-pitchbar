import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AdminLayout from '@/layouts/admin-layout';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type Props = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

/**
 * Account-level pages (profile, security, appearance) live outside both
 * /app and /admin since super_admins also need to update their own
 * profile. We pick the layout chrome at render time based on the
 * authenticated user's role: super_admins get the admin sidebar so
 * they don't see customer-only items (Agents, Inbox, etc.) that they
 * don't have access to anyway, regular customers get the workspace
 * sidebar.
 */
export default function AccountLayout({ children, breadcrumbs }: Props) {
    const { auth } = usePage<{
        auth: { user: { is_super_admin?: boolean } | null };
    }>().props;
    const isAdmin = auth?.user?.is_super_admin === true;

    if (isAdmin) {
        return <AdminLayout breadcrumbs={breadcrumbs}>{children}</AdminLayout>;
    }

    return <AppLayout breadcrumbs={breadcrumbs}>{children}</AppLayout>;
}
