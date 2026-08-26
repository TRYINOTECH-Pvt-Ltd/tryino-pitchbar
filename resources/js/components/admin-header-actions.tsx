import { Link } from '@inertiajs/react';
import {
    Bell,
    Settings2,
    ShieldAlert,
    ShieldCheck,
    ShieldX,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as systemSettings } from '@/routes/settings/system';

type AdminHeaderNotification = {
    key: string;
    title: string;
    body: string;
    severity: 'critical' | 'warning' | 'info';
    href: string;
};

type AdminHeaderPayload = {
    site_health: {
        score: number;
        label: string;
        status: 'healthy' | 'warning' | 'critical';
        ok_checks: number;
        total_checks: number;
        issues_count: number;
    };
    notifications: {
        unread_count: number;
        items: AdminHeaderNotification[];
    };
};

const severityTone = {
    critical: 'bg-destructive/10 text-destructive',
    warning: 'bg-amber-500/12 text-amber-600 dark:text-amber-400',
    info: 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-400',
} as const;

export function AdminHeaderActions({
    header,
}: {
    header: AdminHeaderPayload | null;
}) {
    const { t } = useT();

    if (!header) {
        return null;
    }

    const { site_health: siteHealth, notifications } = header;
    const HealthIcon =
        siteHealth.status === 'healthy'
            ? ShieldCheck
            : siteHealth.status === 'warning'
              ? ShieldAlert
              : ShieldX;

    const healthTone =
        siteHealth.status === 'healthy'
            ? 'border-emerald-500/25 bg-emerald-500/8 text-emerald-700 dark:text-emerald-300'
            : siteHealth.status === 'warning'
              ? 'border-amber-500/25 bg-amber-500/8 text-amber-700 dark:text-amber-300'
              : 'border-destructive/25 bg-destructive/8 text-destructive';
    const healthSettingsHref = `${systemSettings.url()}#health`;

    return (
        <div className="flex items-center gap-2">
            <Link
                href={healthSettingsHref}
                prefetch
                className={cn(
                    'inline-flex h-9 items-center gap-2 rounded-xl border px-2.5 text-xs font-medium shadow-xs transition hover:bg-accent hover:text-accent-foreground',
                    healthTone,
                )}
            >
                <HealthIcon className="size-4" />
                <span className="font-semibold">{siteHealth.score}%</span>
                <span className="hidden text-[11px] text-current/80 xl:inline">
                    {siteHealth.label}
                </span>
            </Link>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="relative size-9 rounded-xl border-border/70 bg-background/80 shadow-xs"
                    >
                        <Bell className="size-4" />
                        {notifications.unread_count > 0 ? (
                            <span className="absolute -end-1 -top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground">
                                {notifications.unread_count > 9
                                    ? '9+'
                                    : notifications.unread_count}
                            </span>
                        ) : null}
                        <span className="sr-only">
                            {t('Open notifications')}
                        </span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-[340px] p-0">
                    <DropdownMenuLabel className="px-3 py-2.5">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-foreground">
                                    {t('Notifications')}
                                </p>
                                <p className="text-xs font-normal text-muted-foreground">
                                    {t(':ok of :total checks healthy', {
                                        ok: siteHealth.ok_checks,
                                        total: siteHealth.total_checks,
                                    })}
                                </p>
                            </div>
                            <span className="text-xs font-medium text-muted-foreground">
                                {notifications.unread_count === 0
                                    ? t('All clear')
                                    : t(':count open', {
                                          count: notifications.unread_count,
                                      })}
                            </span>
                        </div>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {notifications.items.map((item, index) => (
                        <div key={item.key}>
                            <DropdownMenuItem
                                asChild
                                className="cursor-pointer p-0"
                            >
                                <Link
                                    href={item.href}
                                    className="flex w-full items-start gap-3 px-3 py-3"
                                >
                                    <span
                                        className={cn(
                                            'mt-0.5 inline-flex size-2.5 shrink-0 rounded-full',
                                            severityTone[item.severity],
                                        )}
                                    />
                                    <span className="min-w-0 space-y-1">
                                        <span className="block text-sm font-medium text-foreground">
                                            {item.title}
                                        </span>
                                        <span className="block text-xs leading-5 text-muted-foreground">
                                            {item.body}
                                        </span>
                                    </span>
                                </Link>
                            </DropdownMenuItem>
                            {index < notifications.items.length - 1 ? (
                                <DropdownMenuSeparator />
                            ) : null}
                        </div>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <Button
                type="button"
                variant="outline"
                size="icon"
                asChild
                className="size-9 rounded-xl border-border/70 bg-background/80 shadow-xs"
            >
                <Link href={systemSettings()} prefetch>
                    <Settings2 className="size-4" />
                    <span className="sr-only">
                        {t('Open platform settings')}
                    </span>
                </Link>
            </Button>
        </div>
    );
}
