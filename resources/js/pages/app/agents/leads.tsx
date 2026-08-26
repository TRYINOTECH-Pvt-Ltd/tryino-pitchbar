import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronRight,
    Flame,
    Inbox as InboxIcon,
    Mail,
    Phone,
    UserRound,
} from 'lucide-react';
import { LeadScoreBadge } from '@/components/lead-score-badge';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import { index as agentLeadsIndex } from '@/routes/agents/leads';
import { show as showLead } from '@/routes/inbox';
import type { BreadcrumbItem } from '@/types';

type Agent = {
    id: string;
    name: string;
};

type LeadRow = {
    id: string;
    email: string | null;
    name: string | null;
    phone: string | null;
    status: 'new' | 'qualified' | 'contacted' | 'won' | 'lost' | string;
    page_url: string | null;
    created_at: string | null;
    lead_score: number;
    lead_score_bucket: 'low' | 'medium' | 'high';
    lead_score_reasons: string[];
};

type Props = {
    agent: Agent;
    totals: {
        leads: number;
        qualified: number;
        with_email: number;
        with_phone: number;
    };
    leads: LeadRow[];
    pagination: PaginationMeta;
    filters: { q: string; hot: boolean };
};

const STATUS_STYLES: Record<string, string> = {
    new: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300',
    qualified:
        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
    contacted:
        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    won: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300',
    lost: 'border-border bg-muted text-muted-foreground',
};

function useRelativeTime(): (iso: string | null) => string {
    const { t } = useT();

    return (iso: string | null): string => {
        if (!iso) {
            return '—';
        }

        const ms = Date.now() - new Date(iso).getTime();
        const min = Math.max(1, Math.round(ms / 60000));

        if (min < 60) {
            return t(':min m ago', { min });
        }

        const hr = Math.round(min / 60);

        if (hr < 24) {
            return t(':hr h ago', { hr });
        }

        const day = Math.round(hr / 24);

        return t(':day d ago', { day });
    };
}

function StatusBadge({ status }: { status: string }) {
    const { t } = useT();

    return (
        <span
            className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium capitalize ${
                STATUS_STYLES[status] ?? STATUS_STYLES.new
            }`}
        >
            {t(status)}
        </span>
    );
}

export default function AgentLeadsPage({
    agent,
    totals,
    leads,
    pagination,
    filters,
}: Props) {
    const { t } = useT();
    const relativeTime = useRelativeTime();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex() },
        { title: agent.name, href: showAgent(agent.id) },
        { title: t('Leads'), href: agentLeadsIndex({ agent: agent.id }) },
    ];
    const isEmpty = leads.length === 0;
    const hasQuery = filters.q.trim() !== '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · leads`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Leads')}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t(
                                'Every lead captured by :name. Open a row to update the status or jump into the related conversation.',
                                { name: agent.name },
                            )}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant={filters.hot ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => {
                                router.get(
                                    agentLeadsIndex({ agent: agent.id }).url,
                                    {
                                        q: filters.q || undefined,
                                        hot: filters.hot ? undefined : 1,
                                    },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        only: [
                                            'leads',
                                            'pagination',
                                            'filters',
                                        ],
                                    },
                                );
                            }}
                            title={t('Show only leads with score ≥ 70')}
                        >
                            <Flame className="size-4" />
                            <span className="ms-1">{t('Hot only')}</span>
                        </Button>
                        <TableSearch
                            placeholder={t(
                                'Search by name, email, phone, or page URL...',
                            )}
                            initialValue={filters.q}
                            only={['leads', 'pagination', 'filters']}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Total leads')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.leads.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('captured for this agent')}
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('Qualified')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.qualified.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('qualified, contacted, or won')}
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('With email')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.with_email.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('ready for follow-up')}
                        </p>
                    </Card>
                    <Card className="p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            {t('With phone')}
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totals.with_phone.toLocaleString()}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {t('callback-ready contacts')}
                        </p>
                    </Card>
                </div>

                {isEmpty ? (
                    <Card className="p-8 text-center">
                        <InboxIcon className="mx-auto size-10 text-muted-foreground/40" />
                        <p className="mt-3 text-sm font-medium text-foreground">
                            {hasQuery
                                ? t('No leads match that search.')
                                : t('No leads yet for this agent.')}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {hasQuery
                                ? t(
                                      'Try a broader keyword or search by email, phone, or page URL.',
                                  )
                                : t(
                                      'Once visitors share their details, this agent-specific lead feed will populate here.',
                                  )}
                        </p>
                    </Card>
                ) : (
                    <Card className="p-0">
                        <div className="divide-y">
                            {leads.map((lead) => {
                                const label =
                                    lead.name ??
                                    lead.email ??
                                    t('Unnamed lead');

                                return (
                                    <Link
                                        key={lead.id}
                                        href={showLead(lead.id)}
                                        className="flex items-start gap-3 px-4 py-3 transition hover:bg-muted/50"
                                    >
                                        <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                            <UserRound className="size-4" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {label}
                                                </p>
                                                <StatusBadge
                                                    status={lead.status}
                                                />
                                                {lead.lead_score > 0 && (
                                                    <LeadScoreBadge
                                                        score={lead.lead_score}
                                                        bucket={
                                                            lead.lead_score_bucket
                                                        }
                                                        reasons={
                                                            lead.lead_score_reasons
                                                        }
                                                    />
                                                )}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                {lead.email && (
                                                    <span className="inline-flex items-center gap-1.5">
                                                        <Mail className="size-3.5" />
                                                        {lead.email}
                                                    </span>
                                                )}
                                                {lead.phone && (
                                                    <span className="inline-flex items-center gap-1.5">
                                                        <Phone className="size-3.5" />
                                                        {lead.phone}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-1 truncate text-xs text-muted-foreground/90">
                                                {lead.page_url ??
                                                    t('No page URL captured')}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1 text-end">
                                            <span className="text-[11px] text-muted-foreground">
                                                {relativeTime(lead.created_at)}
                                            </span>
                                        </div>
                                        <ChevronRight className="mt-2 size-4 shrink-0 text-muted-foreground" />
                                    </Link>
                                );
                            })}
                        </div>
                        <TablePagination
                            pagination={pagination}
                            only={['leads', 'pagination', 'filters']}
                        />
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
