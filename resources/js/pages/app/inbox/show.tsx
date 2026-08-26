import { Head, router } from '@inertiajs/react';
import { Send, UserCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useAlert } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Lead = {
    id: string;
    email: string | null;
    name: string | null;
    phone: string | null;
    status: 'new' | 'qualified' | 'contacted' | 'won' | 'lost';
    created_at: string | null;
    routed_to: string | null;
};

type DeliveryChannel = { kind: string; label: string; tone: string };

function deliveryChannels(
    routedTo: string | null,
    t: (key: string) => string,
): DeliveryChannel[] {
    if (!routedTo) {
        return [];
    }

    const out: DeliveryChannel[] = [];

    if (routedTo.includes('webhook')) {
        out.push({
            kind: 'webhook',
            label: t('webhook'),
            tone: 'bg-slate-500/15 text-slate-700',
        });
    }

    if (routedTo.includes('slack')) {
        out.push({
            kind: 'slack',
            label: t('Slack'),
            tone: 'bg-purple-500/15 text-purple-700',
        });
    }

    if (routedTo.includes('email')) {
        out.push({
            kind: 'email',
            label: t('email'),
            tone: 'bg-emerald-500/15 text-emerald-700',
        });
    }

    return out;
}

type Message = {
    id: string;
    role: string;
    content: string;
    created_at: string;
};

type ConversationSummary = {
    id: string;
    claimed_by: { id: number; name: string } | null;
    claimed_at: string | null;
} | null;

type Props = {
    lead: Lead;
    messages: Message[];
    conversation: ConversationSummary;
    me: { id: number; name: string };
};

function csrf(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}

export default function LeadShow({ lead, messages, conversation, me }: Props) {
    const { t } = useT();
    const alert = useAlert();
    const [reply, setReply] = useState('');
    const [sending, setSending] = useState(false);
    const [convo, setConvo] = useState<ConversationSummary>(conversation);

    // Defensive dedupe by id. Field reports show the same message id
    // rendering twice (sometimes more) after a `router.reload({ only:
    // ['messages'] })`. Root cause not yet pinned (Inertia partial
    // reload should REPLACE the prop, not merge), but the UI symptom
    // is severe — every operator send appears to double the entire
    // history. Deduping at render is a cheap safety net that prevents
    // user-visible duplication regardless of the upstream cause.
    const dedupedMessages = useMemo(
        () => Array.from(new Map(messages.map((m) => [m.id, m])).values()),
        [messages],
    );

    const isClaimedByMe = convo?.claimed_by?.id === me.id;
    const isClaimedByOther = convo?.claimed_by != null && !isClaimedByMe;

    const claim = async () => {
        if (!convo) {
            return;
        }

        const r = await fetch(`/app/conversations/${convo.id}/claim`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (r.ok) {
            const j = await r.json();
            setConvo({
                ...convo,
                claimed_by: j.data.claimed_by,
                claimed_at: j.data.claimed_at,
            });
        } else if (r.status === 409) {
            const j = await r.json();
            await alert({
                message: t('Already claimed by :name.', {
                    name: j.error?.by?.name ?? t('someone else'),
                }),
            });
        }
    };

    const release = async () => {
        if (!convo) {
            return;
        }

        const r = await fetch(`/app/conversations/${convo.id}/release`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (r.ok) {
            setConvo({ ...convo, claimed_by: null, claimed_at: null });
        }
    };

    const send = async () => {
        if (!convo || !reply.trim()) {
            return;
        }

        setSending(true);

        try {
            const r = await fetch(`/app/conversations/${convo.id}/reply`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ content: reply.trim() }),
            });

            if (r.ok) {
                setReply('');
                router.reload({ only: ['messages'] });
            } else {
                const text = await r.text();
                await alert({
                    message: t('Send failed: :text', { text }),
                });
            }
        } finally {
            setSending(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Inbox'), href: '/app/inbox' },
        { title: lead.email ?? lead.id, href: `/app/inbox/${lead.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Lead · :name', { name: lead.email ?? lead.id })} />
            <div className="grid flex-1 gap-4 p-4 md:grid-cols-[1fr_320px]">
                <Card className="flex flex-col p-4">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="font-medium">{t('Conversation')}</h2>
                        {convo && (
                            <div className="flex items-center gap-2 text-xs">
                                {isClaimedByMe && (
                                    <span className="inline-flex items-center gap-1 rounded bg-emerald-500/15 px-2 py-0.5 text-emerald-700 dark:text-emerald-400">
                                        <UserCheck className="size-3" />{' '}
                                        {t("You're handling this")}
                                    </span>
                                )}
                                {isClaimedByOther && (
                                    <span className="rounded bg-amber-500/15 px-2 py-0.5 text-amber-700 dark:text-amber-400">
                                        {t('With :name', {
                                            name: convo.claimed_by?.name ?? '',
                                        })}
                                    </span>
                                )}
                                {!convo.claimed_by ? (
                                    <Button size="sm" onClick={claim}>
                                        {t('Take over')}
                                    </Button>
                                ) : isClaimedByMe ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={release}
                                    >
                                        {t('Hand back to bot')}
                                    </Button>
                                ) : null}
                            </div>
                        )}
                    </div>
                    <div className="flex flex-1 flex-col gap-3">
                        {dedupedMessages.map((m) => (
                            <div
                                key={m.id}
                                className={`max-w-[85%] rounded-xl px-4 py-2 text-sm ${
                                    m.role === 'user'
                                        ? 'self-end bg-foreground text-background'
                                        : 'self-start bg-muted'
                                }`}
                            >
                                <span className="whitespace-pre-wrap">
                                    {m.content}
                                </span>
                            </div>
                        ))}
                    </div>

                    {isClaimedByMe && (
                        <div className="mt-4 flex gap-2 border-t pt-3">
                            <Textarea
                                rows={2}
                                value={reply}
                                onChange={(e) => setReply(e.target.value)}
                                onKeyDown={(e) => {
                                    if (
                                        e.key === 'Enter' &&
                                        (e.metaKey || e.ctrlKey)
                                    ) {
                                        e.preventDefault();
                                        send();
                                    }
                                }}
                                placeholder={t(
                                    'Type your reply… (Cmd/Ctrl+Enter to send)',
                                )}
                                className="min-h-16 flex-1 resize-none"
                            />
                            <Button
                                onClick={send}
                                disabled={sending || !reply.trim()}
                            >
                                <Send className="size-4" />
                            </Button>
                        </div>
                    )}
                </Card>

                <Card className="h-fit p-4">
                    <h2 className="mb-3 font-medium">{t('Lead')}</h2>
                    <dl className="space-y-2 text-sm">
                        <div>
                            <dt className="text-muted-foreground">
                                {t('Email')}
                            </dt>
                            <dd className="font-medium">{lead.email ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                {t('Name')}
                            </dt>
                            <dd>{lead.name ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                {t('Phone')}
                            </dt>
                            <dd>{lead.phone ?? '—'}</dd>
                        </div>
                    </dl>

                    <div className="mt-4">
                        <p className="mb-1 text-xs text-muted-foreground">
                            {t('Status')}
                        </p>
                        <Select
                            value={lead.status}
                            onValueChange={(v) =>
                                router.patch(
                                    `/app/inbox/${lead.id}`,
                                    { status: v },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[
                                    'new',
                                    'qualified',
                                    'contacted',
                                    'won',
                                    'lost',
                                ].map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {t(s)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {lead.routed_to && (
                        <div className="mt-4">
                            <p className="mb-1 text-xs text-muted-foreground">
                                {t('Delivered to')}
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {deliveryChannels(lead.routed_to, t).map(
                                    (c) => (
                                        <span
                                            key={c.kind}
                                            className={`rounded px-2 py-0.5 text-xs ${c.tone}`}
                                        >
                                            {c.label}
                                        </span>
                                    ),
                                )}
                                {deliveryChannels(lead.routed_to, t).length ===
                                    0 && (
                                    <span className="text-xs text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </div>
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
