import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

type Ticket = {
    id: string;
    subject: string;
    body: string;
    status: 'open' | 'pending' | 'resolved' | 'closed';
    priority: 'low' | 'normal' | 'high' | 'urgent';
    assigned_to_user_id: number | null;
    conversation_id: string | null;
    metadata: Record<string, unknown>;
    created_at: string | null;
    resolved_at: string | null;
};

export default function TicketShow({ ticket }: { ticket: Ticket }) {
    const { t } = useT();
    const form = useForm({
        status: ticket.status,
        priority: ticket.priority,
    });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/app/tickets/${ticket.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={ticket.subject} />
            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Link
                        href="/app/tickets"
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {t('Back to tickets')}
                    </Link>
                </div>

                <Card className="p-6">
                    <h1 className="text-xl font-semibold">{ticket.subject}</h1>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t('Created')}{' '}
                        {ticket.created_at
                            ? new Date(ticket.created_at).toLocaleString()
                            : '—'}
                        {ticket.conversation_id && (
                            <>
                                {' · '}
                                <Link
                                    href={`/app/conversations/${ticket.conversation_id}`}
                                    className="text-primary hover:underline"
                                >
                                    {t('Source conversation')}
                                </Link>
                            </>
                        )}
                    </p>
                    <pre className="mt-4 rounded-md border bg-muted/30 p-4 text-sm leading-6 whitespace-pre-wrap">
                        {ticket.body}
                    </pre>
                </Card>

                <Card className="p-6">
                    <form onSubmit={save} className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <label className="text-xs font-medium">
                                {t('Status')}
                            </label>
                            <select
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData(
                                        'status',
                                        e.target.value as Ticket['status'],
                                    )
                                }
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                            >
                                <option value="open">{t('Open')}</option>
                                <option value="pending">{t('Pending')}</option>
                                <option value="resolved">
                                    {t('Resolved')}
                                </option>
                                <option value="closed">{t('Closed')}</option>
                            </select>
                        </div>
                        <div className="grid gap-1.5">
                            <label className="text-xs font-medium">
                                {t('Priority')}
                            </label>
                            <select
                                value={form.data.priority}
                                onChange={(e) =>
                                    form.setData(
                                        'priority',
                                        e.target.value as Ticket['priority'],
                                    )
                                }
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                            >
                                <option value="low">{t('Low')}</option>
                                <option value="normal">{t('Normal')}</option>
                                <option value="high">{t('High')}</option>
                                <option value="urgent">{t('Urgent')}</option>
                            </select>
                        </div>
                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing
                                    ? t('Saving…')
                                    : t('Save changes')}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </AppLayout>
    );
}
