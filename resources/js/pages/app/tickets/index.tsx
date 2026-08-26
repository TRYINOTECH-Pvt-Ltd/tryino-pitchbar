import { Head, Link, router } from '@inertiajs/react';
import { Ticket as TicketIcon } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

type Ticket = {
    id: string;
    subject: string;
    status: 'open' | 'pending' | 'resolved' | 'closed';
    priority: 'low' | 'normal' | 'high' | 'urgent';
    assignee: string | null;
    conversation_id: string | null;
    created_at: string | null;
    resolved_at: string | null;
};

type Props = {
    tickets: Ticket[];
    filter: { status: string };
    counts: { open: number; pending: number; resolved: number };
};

const STATUS_FILTERS: Array<{ key: string; label: string }> = [
    { key: 'open', label: 'Open' },
    { key: 'pending', label: 'Pending' },
    { key: 'resolved', label: 'Resolved' },
    { key: 'closed', label: 'Closed' },
    { key: 'all', label: 'All' },
];

const PRIORITY_BADGE = {
    urgent: 'bg-rose-100 text-rose-800 border-rose-200',
    high: 'bg-amber-100 text-amber-800 border-amber-200',
    normal: 'bg-slate-100 text-slate-700 border-slate-200',
    low: 'bg-emerald-100 text-emerald-800 border-emerald-200',
} as const;

export default function TicketsIndex({ tickets, filter, counts }: Props) {
    const { t } = useT();

    const setStatus = (status: string) => {
        router.get('/app/tickets', { status }, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={t('Tickets')} />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold">
                            <TicketIcon className="size-5" />
                            {t('Tickets')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Durable support items. Created by the AI when a visitor needs human follow-up, or by your team directly.',
                            )}
                        </p>
                    </div>
                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                        <span>
                            {t(':n open', { n: counts.open.toLocaleString() })}
                        </span>
                        <span>
                            {t(':n pending', {
                                n: counts.pending.toLocaleString(),
                            })}
                        </span>
                        <span>
                            {t(':n resolved', {
                                n: counts.resolved.toLocaleString(),
                            })}
                        </span>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    {STATUS_FILTERS.map((s) => (
                        <button
                            key={s.key}
                            onClick={() => setStatus(s.key)}
                            className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                                filter.status === s.key
                                    ? 'border-foreground bg-foreground text-background'
                                    : 'border-border hover:bg-muted'
                            }`}
                        >
                            {t(s.label)}
                        </button>
                    ))}
                </div>

                <div className="overflow-hidden rounded-md border">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead className="bg-muted/50 text-xs font-medium text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 text-start">
                                    {t('Subject')}
                                </th>
                                <th className="px-3 py-2 text-start">
                                    {t('Priority')}
                                </th>
                                <th className="px-3 py-2 text-start">
                                    {t('Status')}
                                </th>
                                <th className="px-3 py-2 text-start">
                                    {t('Assignee')}
                                </th>
                                <th className="px-3 py-2 text-start">
                                    {t('Created')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {tickets.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-10 text-center text-sm text-muted-foreground"
                                    >
                                        {t('No tickets match this filter yet.')}
                                    </td>
                                </tr>
                            ) : (
                                tickets.map((tk) => (
                                    <tr
                                        key={tk.id}
                                        className="border-t hover:bg-muted/30"
                                    >
                                        <td className="px-3 py-2">
                                            <Link
                                                href={`/app/tickets/${tk.id}`}
                                                className="font-medium text-foreground hover:text-primary"
                                            >
                                                {tk.subject}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            <span
                                                className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium uppercase ${PRIORITY_BADGE[tk.priority]}`}
                                            >
                                                {tk.priority}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground capitalize">
                                            {tk.status}
                                        </td>
                                        <td className="px-3 py-2 text-xs">
                                            {tk.assignee ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">
                                            {tk.created_at
                                                ? new Date(
                                                      tk.created_at,
                                                  ).toLocaleString()
                                                : '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
