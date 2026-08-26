import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    BarChart3,
    Bot,
    CreditCard,
    Inbox,
    LayoutGrid,
    MessagesSquare,
    Plug,
    ScrollText,
    Sparkles,
    Users,
    Workflow as WorkflowIcon,
    Zap,
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
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { useBranding } from '@/hooks/use-branding';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard, onboarding } from '@/routes';
import { index as agentsIndex } from '@/routes/agents';
import { overview as analyticsOverview } from '@/routes/analytics';
import { show as billingShow } from '@/routes/billing';
import { index as conversationsIndex } from '@/routes/conversations';
import { index as inboxIndex } from '@/routes/inbox';
import { index as integrationsIndex } from '@/routes/integrations';
import { index as membersIndex } from '@/routes/members';
import type { NavItem } from '@/types';

type SidebarNavItem = NavItem & {
    badge?: string;
    className?: string;
};

// Sidebar nav titles map to JSON keys translated via the `useT` hook
// inside the component body. The `key` field stays English-stable so
// the "Conversations" badge logic doesn't break when the title is
// translated.
type StableNavItem = SidebarNavItem & { key: string };

function makeMainNavItems(t: (k: string) => string): StableNavItem[] {
    return [
        {
            key: 'Dashboard',
            title: t('Dashboard'),
            href: dashboard(),
            icon: LayoutGrid,
        },
        { key: 'Inbox', title: t('Inbox'), href: inboxIndex(), icon: Inbox },
        {
            key: 'Conversations',
            title: t('Conversations'),
            href: conversationsIndex(),
            icon: MessagesSquare,
        },
        { key: 'Agents', title: t('Agents'), href: agentsIndex(), icon: Bot },
        {
            key: 'Workflows',
            title: t('Workflows'),
            href: '/app/workflows',
            icon: WorkflowIcon,
        },
        {
            key: 'Analytics',
            title: t('Analytics'),
            href: analyticsOverview(),
            icon: BarChart3,
        },
        {
            key: 'Integrations',
            title: t('Integrations'),
            href: integrationsIndex(),
            icon: Plug,
        },
        {
            key: 'Members',
            title: t('Members'),
            href: membersIndex().url,
            icon: Users,
        },
        {
            key: 'Audit',
            title: t('Audit log'),
            href: '/app/audit',
            icon: ScrollText,
        },
        {
            key: 'SystemHealth',
            title: t('System health'),
            href: '/app/system-health',
            icon: Activity,
        },
        {
            key: 'Billing',
            title: t('Billing'),
            href: billingShow(),
            icon: CreditCard,
        },
    ];
}

// Shared shape for both footer cards. Background + border are
// applied per-card so each can pick its own brand accent.
// Shadow is dark-mode only — on a cream sidebar in light mode the
// heavy outer-glow shadow looks like a rendering bug.
const footerCardClassName =
    'group relative flex h-[59px] w-full items-center gap-[14px] overflow-hidden rounded-lg border px-[10px] text-start transition-all duration-200 ' +
    'dark:shadow-[0_22px_48px_-38px_rgba(0,0,0,0.96),inset_0_1px_0_rgba(255,255,255,0.045)]';

const footerIconWrapClassName =
    'relative flex size-9 shrink-0 items-center justify-center rounded-lg border ' +
    'dark:shadow-[0_18px_32px_-22px_rgba(0,0,0,0.92),inset_0_1px_0_rgba(255,255,255,0.16)]';

export function AppSidebar() {
    const branding = useBranding();
    const { t } = useT();
    const { auth, liveHandoff, billingSummary } = usePage<{
        auth: { user: { is_super_admin?: boolean } | null };
        liveHandoff: { needs_human: number; live_now: number } | null;
        billingSummary: {
            plan?: { slug?: string; price_cents?: number } | null;
        } | null;
    }>().props;
    const isAdmin = auth?.user?.is_super_admin === true;
    // Hide the "Upgrade to Pro" CTA once the workspace is on a paid
    // plan. Reuses the same `billingSummary` payload the Billing page
    // reads, so the gate flips immediately after a successful checkout
    // without needing a separate Inertia visit.
    const isOnPaidPlan = (billingSummary?.plan?.price_cents ?? 0) > 0;

    // Show the "Needs human" count as a sidebar badge on Conversations.
    // Match against the stable English `key` (not the translated title)
    // so the badge logic survives locale switches.
    const needsHuman = liveHandoff?.needs_human ?? 0;
    const decoratedNavItems: StableNavItem[] = makeMainNavItems(t).map(
        (item) =>
            item.key === 'Conversations' && needsHuman > 0
                ? { ...item, badge: String(needsHuman) }
                : item,
    );
    // SystemHealth is platform-admin-only. Workspace owners/admins
    // were seeing it before but the page is really an operator/
    // observability surface — moved out of the customer sidebar.
    // Controller still 403s non-admin workspace roles, so this just
    // removes the nav surface.
    const customerNavItems = decoratedNavItems
        .filter((item) => !(isAdmin && item.key === 'Billing'))
        .filter((item) => item.key !== 'SystemHealth' || isAdmin);

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
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-0 py-3">
                <NavMain items={customerNavItems} />
            </SidebarContent>

            <SidebarFooter className="gap-3 border-t border-sidebar-border/70 p-[11px] group-data-[collapsible=icon]:p-2">
                <div className="space-y-[9px] group-data-[collapsible=icon]:hidden">
                    <WorkspaceSwitcher />

                    <Link
                        href={onboarding()}
                        className={cn(
                            footerCardClassName,
                            // Light: white surface, soft violet tint, violet border + accent
                            'border-violet-200 bg-violet-50 hover:border-violet-300 hover:bg-violet-100',
                            // Dark: rich glow gradient (the original treatment)
                            'dark:border-violet-300/14 dark:bg-[radial-gradient(circle_at_18%_24%,rgba(122,91,255,0.22),transparent_38%),linear-gradient(105deg,rgba(24,23,36,0.98),rgba(16,17,25,0.98)_64%,rgba(22,23,37,0.98))] dark:hover:border-violet-200/24 dark:hover:bg-[radial-gradient(circle_at_18%_24%,rgba(147,118,255,0.28),transparent_40%),linear-gradient(105deg,rgba(28,26,43,1),rgba(17,18,27,1)_66%,rgba(25,26,42,1))]',
                        )}
                    >
                        <span
                            className={cn(
                                footerIconWrapClassName,
                                // Light: solid violet tile, white icon
                                'border-violet-300 bg-violet-500',
                                // Dark: glassy violet gradient
                                'dark:border-violet-200/18 dark:bg-[radial-gradient(circle_at_72%_24%,rgba(255,255,255,0.22),transparent_18%),linear-gradient(180deg,rgba(92,72,159,0.62),rgba(42,36,67,0.88))]',
                            )}
                        >
                            <Sparkles className="size-5 text-white dark:drop-shadow-[0_0_10px_rgba(221,214,254,0.35)]" />
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col justify-center gap-1.5">
                            <span className="truncate text-[13px] leading-none font-semibold text-violet-950 dark:text-white/96">
                                {t('Getting Started')}
                            </span>
                            <span className="truncate text-[11px] leading-none font-medium text-violet-700/80 dark:text-white/54">
                                {t('Set up :name in a few steps', {
                                    name: branding.site_title,
                                })}
                            </span>
                        </span>
                        <ArrowRight className="ms-auto size-4 shrink-0 text-violet-700 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:text-violet-900 dark:text-white/78 dark:group-hover:text-white" />
                    </Link>

                    {!isAdmin && !isOnPaidPlan && (
                        <Link
                            href={billingShow()}
                            className={cn(
                                footerCardClassName,
                                // Light: sky-tinted card, dark text
                                'border-sky-200 bg-sky-50 hover:border-sky-300 hover:bg-sky-100',
                                // Dark: glow gradient
                                'dark:border-sky-300/18 dark:bg-[radial-gradient(circle_at_18%_24%,rgba(15,118,255,0.30),transparent_36%),linear-gradient(105deg,rgba(8,25,42,0.98),rgba(10,13,18,0.98)_64%,rgba(11,15,21,0.98))] dark:hover:border-sky-200/28 dark:hover:bg-[radial-gradient(circle_at_18%_24%,rgba(30,134,255,0.36),transparent_38%),linear-gradient(105deg,rgba(8,31,54,1),rgba(10,14,20,1)_66%,rgba(11,16,23,1))]',
                            )}
                        >
                            <span
                                className={cn(
                                    footerIconWrapClassName,
                                    'border-sky-300 bg-[linear-gradient(180deg,#2f86ff,#164fff)] text-white',
                                    'dark:border-sky-200/34 dark:shadow-[0_0_34px_-12px_rgba(37,99,235,0.92),inset_0_1px_0_rgba(255,255,255,0.2)]',
                                )}
                            >
                                <Zap className="size-5 dark:drop-shadow-[0_0_8px_rgba(255,255,255,0.45)]" />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col justify-center gap-1.5">
                                <span className="truncate text-[13px] leading-none font-semibold text-sky-950 dark:text-white/96">
                                    {t('Upgrade to Pro')}
                                </span>
                                <span className="truncate text-[11px] leading-none font-medium text-sky-700/80 dark:text-white/54">
                                    {t('Unlock more conversations')}
                                </span>
                            </span>
                            <ArrowRight className="ms-auto size-4 shrink-0 text-sky-600 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:text-sky-800 dark:text-blue-400 dark:group-hover:text-blue-200" />
                        </Link>
                    )}
                </div>

                <SidebarMenu className="hidden group-data-[collapsible=icon]:flex group-data-[collapsible=icon]:flex-col">
                    <SidebarMenuItem>
                        <WorkspaceSwitcher />
                    </SidebarMenuItem>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            tooltip={{ children: t('Getting Started') }}
                            className="h-9 rounded-xl border border-sidebar-border/60 bg-sidebar-accent/35"
                        >
                            <Link href={onboarding()}>
                                <Sparkles className="size-4" />
                                <span>{t('Getting Started')}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    {!isAdmin && !isOnPaidPlan && (
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                asChild
                                tooltip={{ children: t('Upgrade to Pro') }}
                                className="h-9 rounded-xl border border-sidebar-border/60 bg-sidebar-accent/35"
                            >
                                <Link href={billingShow()}>
                                    <CreditCard className="size-4" />
                                    <span>{t('Upgrade to Pro')}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    )}
                </SidebarMenu>

                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
