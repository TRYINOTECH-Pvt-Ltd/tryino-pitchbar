import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useT } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editLocale } from '@/routes/locale';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as apiTokensSettings } from '@/routes/settings/api-tokens';
import { index as brandingSettings } from '@/routes/settings/branding';
import { edit as editByokKeys } from '@/routes/settings/byok-keys';
import { index as marketingSettings } from '@/routes/settings/marketing';
import { index as privacySettings } from '@/routes/settings/privacy';
import { index as systemSettings } from '@/routes/settings/system';
import type { NavItem } from '@/types';

type NavGroup = {
    title: string;
    items: NavItem[];
};

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useT();
    const { auth, byok, workspaceRole } = usePage<{
        auth: { user: { is_super_admin?: boolean } | null };
        byok: { unlocked: boolean } | null;
        workspaceRole: string | null;
    }>().props;
    const isAdmin = auth?.user?.is_super_admin === true;
    // Hide entries that 403 for non-admin workspace members. Owner +
    // Admin can manage workspace settings; Editor + Viewer cannot.
    // Customers (no workspace pivot) also fall through to false.
    const canManageWorkspace =
        workspaceRole === 'owner' || workspaceRole === 'admin';
    // Surface the BYOK entry only when ByokResolver says this user
    // × workspace pair is unlocked. The /settings/byok-keys page
    // 404s for everyone else, so showing the link conditionally is
    // what matters — otherwise customers whose operator enrolled
    // BYOK had no way to discover where to paste their keys.
    const byokUnlocked = byok?.unlocked === true;

    const baseNavItems: NavItem[] = [
        { title: t('Profile'), href: edit(), icon: null },
        { title: t('Security'), href: editSecurity(), icon: null },
        { title: t('Appearance'), href: editAppearance(), icon: null },
        { title: t('Language'), href: editLocale(), icon: null },
        { title: t('Widget'), href: '/settings/widget', icon: null },
        { title: t('API tokens'), href: apiTokensSettings(), icon: null },
        ...(byokUnlocked
            ? [{ title: t('AI keys'), href: editByokKeys(), icon: null }]
            : []),
    ];

    // Workspace-scoped settings pages already exist as standalone Inertia
    // pages (/app/settings/tags, /app/settings/canned-replies) but had no
    // sidebar entry to discover them — buyer searched in Settings and
    // hit a dead end. Surface them here so the Inbox tag dropdown's
    // "Create tags →" hint resolves to a visible nav row.
    // "Workspace name" gates on owner/admin since the controller 403s
    // for editors + viewers (WorkspacePolicy::update). Tags + Canned
    // replies index controllers have no role gate so every member of
    // the workspace can browse them, even if mutations are admin-only.
    const workspaceNavItems: NavItem[] = [
        ...(canManageWorkspace
            ? [
                  {
                      title: t('Workspace name'),
                      href: '/settings/workspace',
                      icon: null,
                  },
              ]
            : []),
        { title: t('Tags'), href: '/app/settings/tags', icon: null },
        {
            title: t('Canned replies'),
            href: '/app/settings/canned-replies',
            icon: null,
        },
    ];

    const adminOnlyNavItems: NavItem[] = [
        { title: t('System'), href: systemSettings(), icon: null },
        {
            title: t('Widget defaults'),
            href: '/settings/widget-defaults',
            icon: null,
        },
        { title: t('Branding'), href: brandingSettings(), icon: null },
        { title: t('Marketing site'), href: marketingSettings(), icon: null },
        { title: t('Privacy & GDPR'), href: privacySettings(), icon: null },
        {
            title: t('Widget Monitor'),
            href: '/settings/system/widget-monitor',
            icon: null,
        },
        {
            title: t('Hot path latency'),
            href: '/settings/system/hotpath-latency',
            icon: null,
        },
    ];

    // Workspace section is workspace-member-only. Super_admins manage
    // the platform at /admin/* (workspaces, plans, subscriptions) and
    // have no membership row for any tenant's workspace by default, so
    // the entries here would 403 / 404 from their account. Hide the
    // whole group for super_admins; show it to every workspace member
    // (owner/admin/editor/viewer). Buyer-reported 2026-05-20.
    const navGroups: NavGroup[] = isAdmin
        ? [
              { title: t('Account'), items: baseNavItems },
              { title: t('Platform'), items: adminOnlyNavItems },
          ]
        : [
              { title: t('Account'), items: baseNavItems },
              { title: t('Workspace'), items: workspaceNavItems },
          ];

    return (
        <div className="px-4 py-6 lg:px-6">
            <Heading
                title={t('Settings')}
                description={t('Manage your profile and account settings')}
            />

            <div className="mt-6 grid gap-8 xl:grid-cols-[240px_minmax(0,1fr)] xl:items-start xl:gap-10">
                <aside className="w-full xl:sticky xl:top-6">
                    <div className="overflow-hidden rounded-2xl border border-border/70 bg-muted/20 shadow-sm">
                        {navGroups.map((group, groupIndex) => (
                            <div key={group.title}>
                                <div className="p-3">
                                    <p className="px-3 pb-2 text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                        {group.title}
                                    </p>
                                    <nav
                                        className="grid gap-1"
                                        aria-label={`${group.title} settings`}
                                    >
                                        {group.items.map((item, index) => {
                                            const isActive =
                                                isCurrentOrParentUrl(item.href);

                                            return (
                                                <Link
                                                    key={`${toUrl(item.href)}-${index}`}
                                                    href={item.href}
                                                    className={cn(
                                                        'flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                                                        {
                                                            'bg-background text-foreground shadow-sm ring-1 ring-border/80':
                                                                isActive,
                                                            'text-muted-foreground hover:bg-background/80 hover:text-foreground':
                                                                !isActive,
                                                        },
                                                    )}
                                                >
                                                    {item.icon && (
                                                        <item.icon className="h-4 w-4" />
                                                    )}
                                                    <span>{item.title}</span>
                                                </Link>
                                            );
                                        })}
                                    </nav>
                                </div>

                                {groupIndex < navGroups.length - 1 ? (
                                    <Separator />
                                ) : null}
                            </div>
                        ))}
                    </div>
                </aside>

                <div className="min-w-0">
                    <section className="w-full max-w-[1120px] space-y-8">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
