import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Lightbulb, Plus, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Gap = {
    id: string;
    agent_id: string;
    question: string;
    occurrences: number;
    last_seen_at: string | null;
    status: 'open' | 'answered' | 'ignored';
};

type AgentRef = { id: string; name: string };

type Filters = {
    agent_id: string;
    status: 'open' | 'answered' | 'ignored' | string;
    window: '7' | '30' | '90' | 'all' | string;
};

type Props = {
    gaps: Gap[];
    agents: AgentRef[];
    filters: Filters;
};

const ANY_AGENT = '__any__';

const STATUS_STYLES: Record<string, string> = {
    open: 'border-amber-200 bg-amber-500/15 text-amber-800 dark:text-amber-300',
    answered:
        'border-emerald-200 bg-emerald-500/15 text-emerald-800 dark:text-emerald-300',
    ignored: 'border-border bg-muted text-muted-foreground',
};

export default function ContentGaps({ gaps, agents, filters }: Props) {
    const { t } = useT();
    const [draft, setDraft] = useState<Filters>(filters);
    const agentById = new Map(agents.map((a) => [a.id, a]));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Analytics'), href: '/app/analytics' },
        { title: t('Content gaps'), href: '/app/analytics/content-gaps' },
    ];

    const apply = (overrides: Partial<Filters> = {}) => {
        const merged = { ...draft, ...overrides };
        const params: Record<string, string> = {};
        (Object.keys(merged) as (keyof Filters)[]).forEach((key) => {
            const v = merged[key];

            if (typeof v === 'string' && v.trim() !== '') {
                params[key] = v;
            }
        });
        router.get('/app/analytics/content-gaps', params, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const resolve = (gap: Gap, action: 'answered' | 'ignore') => {
        router.post(
            `/app/analytics/gaps/${gap.id}/resolve`,
            { action },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Content gaps')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-3">
                    <Lightbulb className="h-6 w-6 text-amber-500" />
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('Content gaps')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                "Questions visitors asked that the agent couldn't answer well — your top opportunities to add curated answers or new sources.",
                            )}
                        </p>
                    </div>
                </div>

                <Card className="p-4">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="space-y-1">
                            <Label htmlFor="gap-agent">{t('Agent')}</Label>
                            <Select
                                value={
                                    draft.agent_id === ''
                                        ? ANY_AGENT
                                        : draft.agent_id
                                }
                                onValueChange={(v) =>
                                    setDraft((d) => ({
                                        ...d,
                                        agent_id: v === ANY_AGENT ? '' : v,
                                    }))
                                }
                            >
                                <SelectTrigger id="gap-agent">
                                    <SelectValue placeholder={t('Any agent')} />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY_AGENT}>
                                        {t('Any agent')}
                                    </SelectItem>
                                    {agents.map((a) => (
                                        <SelectItem key={a.id} value={a.id}>
                                            {a.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="gap-status">{t('Status')}</Label>
                            <Select
                                value={draft.status}
                                onValueChange={(v) =>
                                    setDraft((d) => ({ ...d, status: v }))
                                }
                            >
                                <SelectTrigger id="gap-status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="open">
                                        {t('Open')}
                                    </SelectItem>
                                    <SelectItem value="answered">
                                        {t('Answered')}
                                    </SelectItem>
                                    <SelectItem value="ignored">
                                        {t('Ignored')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="gap-window">{t('Window')}</Label>
                            <Select
                                value={draft.window}
                                onValueChange={(v) =>
                                    setDraft((d) => ({ ...d, window: v }))
                                }
                            >
                                <SelectTrigger id="gap-window">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="7">
                                        {t('Last 7 days')}
                                    </SelectItem>
                                    <SelectItem value="30">
                                        {t('Last 30 days')}
                                    </SelectItem>
                                    <SelectItem value="90">
                                        {t('Last 90 days')}
                                    </SelectItem>
                                    <SelectItem value="all">
                                        {t('All time')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="mt-4 flex justify-end">
                        <Button onClick={() => apply()}>{t('Apply')}</Button>
                    </div>
                </Card>

                <Card className="p-4">
                    {gaps.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t(
                                "No gaps match the current filters. Either visitors aren't asking unanswerable questions, or your team has already triaged them.",
                            )}
                        </p>
                    ) : (
                        <div className="divide-y">
                            {gaps.map((g) => {
                                const agent = agentById.get(g.agent_id);
                                const curatedHref = agent
                                    ? `/app/agents/${agent.id}/curated?prefill=${encodeURIComponent(g.question)}`
                                    : null;

                                return (
                                    <div
                                        key={g.id}
                                        className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {g.question}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {agent && (
                                                    <span className="mr-2">
                                                        {agent.name}
                                                    </span>
                                                )}
                                                {t('asked :count times', {
                                                    count: g.occurrences,
                                                })}
                                                {g.last_seen_at && (
                                                    <>
                                                        {' '}
                                                        {t('· last :time', {
                                                            time: new Date(
                                                                g.last_seen_at,
                                                            ).toLocaleString(),
                                                        })}
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span
                                                className={`rounded border px-2 py-0.5 text-xs ${
                                                    STATUS_STYLES[g.status] ??
                                                    STATUS_STYLES.open
                                                }`}
                                            >
                                                {t(g.status)}
                                            </span>
                                            {g.status === 'open' && (
                                                <>
                                                    {curatedHref && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={
                                                                    curatedHref
                                                                }
                                                            >
                                                                <Plus className="mr-1 h-3.5 w-3.5" />
                                                                {t(
                                                                    'Curated answer',
                                                                )}
                                                            </Link>
                                                        </Button>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            resolve(
                                                                g,
                                                                'answered',
                                                            )
                                                        }
                                                    >
                                                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                                                        {t('Resolve')}
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            resolve(g, 'ignore')
                                                        }
                                                    >
                                                        <XCircle className="mr-1 h-3.5 w-3.5" />
                                                        {t('Ignore')}
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
