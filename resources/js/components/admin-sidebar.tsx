import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Bot,
    Building2,
    CreditCard,
    FileText,
    Gauge,
    Inbox,
    Languages,
    LayoutDashboard,
    MessagesSquare,
    Server,
    Tags,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useBranding } from '@/hooks/use-branding';
import { useT } from '@/lib/i18n';
import type { NavItem } from '@/types';

export function AdminSidebar() {
    const branding = useBranding();
    const { t, multilingual } = useT();

    const adminNav: NavItem[] = [
        { title: t('Dashboard'), href: '/admin', icon: LayoutDashboard },
        { title: t('Workspaces'), href: '/admin/workspaces', icon: Building2 },
        { title: t('Users'), href: '/admin/users', icon: Users },
        { title: t('Agents'), href: '/admin/agents', icon: Bot },
        {
            title: t('Conversations'),
            href: '/admin/conversations',
            icon: MessagesSquare,
        },
        { title: t('Leads'), href: '/admin/leads', icon: Inbox },
        { title: t('Pages'), href: '/admin/pages', icon: FileText },
        // Translation manager is only meaningful when the multilingual
        // system is enabled (MULTILINGUAL_ENABLED); hide it otherwise.
        ...(multilingual
            ? [
                  {
                      title: t('Translations'),
                      href: '/admin/translations',
                      icon: Languages,
                  },
              ]
            : []),
        { title: t('Plans'), href: '/admin/plans', icon: Tags },
        {
            title: t('Subscriptions'),
            href: '/admin/subscriptions',
            icon: CreditCard,
        },
        { title: t('Usage'), href: '/admin/usage', icon: Gauge },
        {
            title: t('Queue Health'),
            href: '/admin/queue-health',
            icon: Server,
        },
        {
            title: t('Queue Failures'),
            href: '/admin/jobs/failed',
            icon: AlertTriangle,
        },
        // Internal Kanban board lives at /admin/board but is intentionally
        // not surfaced in this nav — it's an internal-team-only tool, not
        // a feature buyers should see in their sidebar. Reach it directly
        // via the URL or the docs page.
    ];

    return (
        <Sidebar collapsible="icon" className="border-e border-sidebar-border">
            <SidebarHeader className="border-b border-sidebar-border/80 p-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            tooltip={{ children: branding.site_title }}
                            className="h-11 rounded-xl px-3 transition-colors group-data-[collapsible=icon]:size-10! group-data-[collapsible=icon]:p-0! hover:bg-sidebar-accent/60"
                        >
                            <Link href="/admin" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-0 py-3">
                <NavMain items={adminNav} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
