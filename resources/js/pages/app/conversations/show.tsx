import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Hand,
    Headset,
    Lock,
    LogOut,
    NotebookPen,
    PanelRightClose,
    PanelRightOpen,
    Send,
    Settings2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CannedReplyPicker } from '@/components/live-chat/canned-reply-picker';
import type { CannedReplyOption } from '@/components/live-chat/canned-reply-picker';
import { ConversationContextPanel } from '@/components/live-chat/conversation-context-panel';
import type { ContextPanelProps } from '@/components/live-chat/conversation-context-panel';
import type { TagOption } from '@/components/live-chat/tag-picker';
import { TransferDialog } from '@/components/live-chat/transfer-dialog';
import type { TransferTarget } from '@/components/live-chat/transfer-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Citation = { id: number; url: string | null };

type Msg = {
    id: string;
    role: 'user' | 'assistant' | 'human-agent' | 'internal-note';
    content: string;
    citations: Citation[];
    confidence: number | null;
    created_at: string | null;
    is_human?: boolean;
    is_note?: boolean;
    is_system?: boolean;
};

type Lead = {
    id: string;
    email: string | null;
    name: string | null;
    phone: string | null;
};

type TrajectoryEntry = {
    url: string;
    title: string | null;
    referrer: string | null;
    viewed_at: string | null;
    in_this_conversation: boolean;
};

type Props = {
    agent: { id: string; name: string };
    conversation: {
        id: string;
        started_at: string | null;
        page_url: string | null;
        lang: string | null;
        visitor_anon: string | null;
        human_requested_at: string | null;
        claimed_at: string | null;
        claimed_by: { id: number; name: string } | null;
        lead: Lead | null;
        visitor_typing: boolean;
        satisfaction: 'positive' | 'negative' | null;
        satisfaction_at: string | null;
        satisfaction_comment: string | null;
        is_returning: boolean;
        lead_score: number;
        lead_score_bucket: 'low' | 'medium' | 'high';
        lead_score_reasons: string[];
        lead_score_updated_at: string | null;
    };
    messages: Msg[];
    trajectory: TrajectoryEntry[];
    teammates: TransferTarget[];
    canned_replies: CannedReplyOption[];
    applied_tags: TagOption[];
    available_tags: TagOption[];
    auth_user_id: number;
    can_takeover: boolean;
};

function fmtTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleString();
}

function relativeTime(
    iso: string | null,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (!iso) {
        return '';
    }

    const diffMs = Date.now() - new Date(iso).getTime();
    const m = Math.floor(diffMs / 60_000);

    if (m < 1) {
        return t('just now');
    }

    if (m < 60) {
        return t(':count m ago', { count: m });
    }

    const h = Math.floor(m / 60);

    if (h < 24) {
        return t(':count h ago', { count: h });
    }

    return t(':count d ago', { count: Math.floor(h / 24) });
}

function durationLabel(iso: string): string {
    const sec = Math.max(
        0,
        Math.floor((Date.now() - new Date(iso).getTime()) / 1000),
    );

    if (sec < 60) {
        return `${sec}s`;
    }

    const min = Math.floor(sec / 60);

    if (min < 60) {
        const s = sec % 60;

        return s === 0 ? `${min}m` : `${min}m ${s}s`;
    }

    const hr = Math.floor(min / 60);

    return `${hr}h ${min % 60}m`;
}

export default function ConversationShow({
    agent,
    conversation,
    messages,
    trajectory,
    teammates,
    canned_replies,
    applied_tags,
    available_tags,
    auth_user_id,
    can_takeover,
}: Props) {
    const { t } = useT();
    const [isLive, setIsLive] = useState(true);
    const claimedByMe =
        conversation.claimed_by !== null &&
        conversation.claimed_by.id === auth_user_id;
    const claimedByOther = conversation.claimed_by !== null && !claimedByMe;
    const isLiveTakeover =
        conversation.claimed_by !== null ||
        conversation.human_requested_at !== null;
    const pollInterval = isLiveTakeover ? 2000 : 8000;

    const [reply, setReply] = useState('');
    const [replyMode, setReplyMode] = useState<'reply' | 'note'>('reply');
    const [busy, setBusy] = useState(false);
    const [contextOpen, setContextOpen] = useState(true);
    const [mobileContextOpen, setMobileContextOpen] = useState(false);

    // Defensive dedupe by id. Field reports show the same message id
    // rendering twice (sometimes more) after a `router.reload({ only:
    // ['messages'] })`. Inertia partial reload should REPLACE the
    // prop, not merge, but the user-visible symptom is severe — every
    // operator send appears to double the entire history. Deduping
    // at render is a cheap safety net that prevents the duplication
    // regardless of upstream cause (a duplicate-key warning from
    // React would also surface).
    const dedupedMessages = useMemo(
        () => Array.from(new Map(messages.map((m) => [m.id, m])).values()),
        [messages],
    );

    const messagesEndRef = useRef<HTMLDivElement | null>(null);
    const lastTypingFireRef = useRef(0);

    useEffect(() => {
        if (!isLive) {
            return;
        }

        const tick = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            router.reload({
                only: ['conversation', 'messages', 'applied_tags'],
            });
        };
        const interval = window.setInterval(tick, pollInterval);

        return () => window.clearInterval(interval);
    }, [isLive, pollInterval]);

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [dedupedMessages.length]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Conversations'), href: '/app/conversations' },
        { title: t('Thread'), href: `/app/conversations/${conversation.id}` },
    ];

    const csrfHeader = (): Record<string, string> => ({
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN':
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? '',
        'X-Requested-With': 'XMLHttpRequest',
    });

    const claim = async () => {
        setBusy(true);

        try {
            const res = await fetch(
                `/app/conversations/${conversation.id}/claim`,
                {
                    method: 'POST',
                    headers: csrfHeader(),
                    credentials: 'same-origin',
                },
            );

            if (!res.ok) {
                if (res.status === 409) {
                    toast.error(t('Already claimed by another operator.'));
                } else {
                    toast.error(t('Could not claim — try again.'));
                }

                return;
            }

            toast.success(t('You are now in this conversation.'));
            router.reload({ only: ['conversation', 'messages'] });
        } finally {
            setBusy(false);
        }
    };

    const release = async () => {
        if (busy) {
            return;
        }

        setBusy(true);

        try {
            await fetch(`/app/conversations/${conversation.id}/release`, {
                method: 'POST',
                headers: csrfHeader(),
                credentials: 'same-origin',
            });
            toast.success(t('Released — bot will resume on the next message.'));
            router.reload({ only: ['conversation', 'messages'] });
        } finally {
            setBusy(false);
        }
    };

    const sendReply = async (e?: React.FormEvent) => {
        e?.preventDefault();
        const text = reply.trim();

        if (!text || busy) {
            return;
        }

        setBusy(true);
        const endpoint =
            replyMode === 'note'
                ? `/app/conversations/${conversation.id}/note`
                : `/app/conversations/${conversation.id}/reply`;

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: csrfHeader(),
                credentials: 'same-origin',
                body: JSON.stringify({ content: text }),
            });

            if (!res.ok) {
                if (res.status === 409) {
                    toast.error(t('You need to claim the conversation first.'));
                } else {
                    toast.error(t('Failed to send — please try again.'));
                }

                return;
            }

            setReply('');
            router.reload({ only: ['conversation', 'messages'] });
        } finally {
            setBusy(false);
        }
    };

    const onReplyKeydown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            void sendReply();
        }
    };

    const onReplyChange = (value: string) => {
        setReply(value);

        if (replyMode !== 'reply' || !claimedByMe) {
            return;
        }

        const now = Date.now();

        if (now - lastTypingFireRef.current < 2000) {
            return;
        }

        lastTypingFireRef.current = now;
        fetch(`/app/conversations/${conversation.id}/typing`, {
            method: 'POST',
            headers: csrfHeader(),
            credentials: 'same-origin',
        }).catch(() => {});
    };

    const transfer = async (targetUserId: number) => {
        if (busy) {
            return;
        }

        setBusy(true);

        try {
            const res = await fetch(
                `/app/conversations/${conversation.id}/transfer`,
                {
                    method: 'POST',
                    headers: csrfHeader(),
                    credentials: 'same-origin',
                    body: JSON.stringify({ target_user_id: targetUserId }),
                },
            );

            if (!res.ok) {
                toast.error(
                    t('Could not transfer — target may not have permission.'),
                );

                return;
            }

            toast.success(t('Conversation transferred.'));
            router.reload({ only: ['conversation', 'messages'] });
        } finally {
            setBusy(false);
        }
    };

    const attachTag = async (tagId: string) => {
        const res = await fetch(
            `/app/conversations/${conversation.id}/tags/${tagId}`,
            {
                method: 'POST',
                headers: csrfHeader(),
                credentials: 'same-origin',
            },
        );

        if (res.ok) {
            router.reload({ only: ['applied_tags'] });
        }
    };

    const detachTag = async (tagId: string) => {
        const res = await fetch(
            `/app/conversations/${conversation.id}/tags/${tagId}`,
            {
                method: 'DELETE',
                headers: csrfHeader(),
                credentials: 'same-origin',
            },
        );

        if (res.ok) {
            router.reload({ only: ['applied_tags'] });
        }
    };

    // SLA tile — most useful signal in any given moment.
    // Priority: claimed → "in conversation for X" / waiting → "waiting Y" / idle → started Z ago
    const sla = useMemo<ContextPanelProps['sla']>(() => {
        if (conversation.claimed_at) {
            return {
                label: t('In chat for'),
                value: durationLabel(conversation.claimed_at),
            };
        }

        if (conversation.human_requested_at) {
            return {
                label: t('Waiting'),
                value: durationLabel(conversation.human_requested_at),
            };
        }

        if (conversation.started_at) {
            return {
                label: t('Started'),
                value: relativeTime(conversation.started_at, t),
            };
        }

        return null;
    }, [
        conversation.claimed_at,
        conversation.human_requested_at,
        conversation.started_at,
        t,
    ]);

    const contextPanel = (
        <ConversationContextPanel
            visitor={{
                anon: conversation.visitor_anon,
                lang: conversation.lang,
                is_returning: conversation.is_returning,
                page_url: conversation.page_url,
            }}
            lead={conversation.lead}
            appliedTags={applied_tags}
            availableTags={available_tags}
            onAttachTag={attachTag}
            onDetachTag={detachTag}
            satisfaction={conversation.satisfaction}
            satisfactionAt={conversation.satisfaction_at}
            satisfactionComment={conversation.satisfaction_comment}
            sla={sla}
            conversationStartedAt={conversation.started_at}
            leadScore={conversation.lead_score}
            leadScoreBucket={conversation.lead_score_bucket}
            leadScoreReasons={conversation.lead_score_reasons}
            trajectory={trajectory}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Conversation · :name', { name: agent.name })} />
            <div className="flex flex-1 gap-3 overflow-hidden p-3 md:p-4">
                {/* Left pane: chat + composer */}
                <Card className="flex flex-1 flex-col overflow-hidden">
                    {/* Header strip — name, live pill, action cluster */}
                    <header className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                        <Link
                            href="/app/conversations"
                            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-3" />
                            {t('All')}
                        </Link>
                        <span className="ms-1 truncate text-sm font-semibold">
                            {agent.name}
                        </span>
                        <button
                            type="button"
                            onClick={() => setIsLive(!isLive)}
                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase transition ${
                                isLive
                                    ? 'bg-emerald-500/15 text-emerald-700 hover:bg-emerald-500/25 dark:text-emerald-400'
                                    : 'bg-muted text-muted-foreground hover:bg-muted/70'
                            }`}
                            title={
                                isLive
                                    ? t('Auto-refreshing every :seconds s', {
                                          seconds: pollInterval / 1000,
                                      })
                                    : t('Click to resume auto-refresh')
                            }
                        >
                            <span
                                className={`size-1.5 rounded-full ${
                                    isLive
                                        ? 'animate-pulse bg-emerald-500'
                                        : 'bg-muted-foreground/50'
                                }`}
                            />
                            {isLive ? t('Live') : t('Paused')}
                        </button>
                        {conversation.human_requested_at &&
                            !conversation.claimed_by && (
                                <Badge
                                    variant="outline"
                                    className="border-amber-300 bg-amber-50 text-[10px] font-semibold tracking-wider text-amber-800 uppercase"
                                >
                                    {t('Needs human')} ·{' '}
                                    {relativeTime(
                                        conversation.human_requested_at,
                                        t,
                                    )}
                                </Badge>
                            )}
                        {claimedByOther && (
                            <Badge
                                variant="outline"
                                className="border-slate-300 bg-slate-50 text-[10px] font-semibold tracking-wider text-slate-700 uppercase"
                            >
                                <Lock className="me-1 size-3" />
                                {conversation.claimed_by!.name}
                            </Badge>
                        )}
                        {claimedByMe && (
                            <Badge
                                variant="default"
                                className="bg-emerald-600 text-[10px] font-semibold tracking-wider text-white uppercase"
                            >
                                <Headset className="me-1 size-3" />
                                {t("You're in")}
                            </Badge>
                        )}
                        <div className="ms-auto flex items-center gap-1.5">
                            {can_takeover && !conversation.claimed_by && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={claim}
                                    disabled={busy}
                                >
                                    <Hand className="me-1.5 size-3.5" />
                                    {t('Claim')}
                                </Button>
                            )}
                            {can_takeover && claimedByMe && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={release}
                                    disabled={busy}
                                >
                                    <LogOut className="me-1.5 size-3.5" />
                                    {t('Release')}
                                </Button>
                            )}
                            {can_takeover &&
                                claimedByOther &&
                                conversation.human_requested_at && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={release}
                                        disabled={busy}
                                        title={t(
                                            "Force-release another operator's claim",
                                        )}
                                    >
                                        <CheckCircle2 className="me-1.5 size-3.5" />
                                        {t('Force release')}
                                    </Button>
                                )}
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setContextOpen((v) => !v)}
                                title={
                                    contextOpen
                                        ? t('Hide details')
                                        : t('Show details')
                                }
                                className="hidden md:inline-flex"
                            >
                                {contextOpen ? (
                                    <PanelRightClose className="size-4" />
                                ) : (
                                    <PanelRightOpen className="size-4" />
                                )}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setMobileContextOpen(true)}
                                className="md:hidden"
                            >
                                <Settings2 className="me-1.5 size-3.5" />
                                {t('Details')}
                            </Button>
                        </div>
                    </header>

                    {/* Message log */}
                    <div className="flex flex-1 flex-col gap-2 overflow-y-auto p-4">
                        {dedupedMessages.length === 0 ? (
                            <p className="text-center text-sm text-muted-foreground">
                                {t('No messages yet.')}
                            </p>
                        ) : (
                            dedupedMessages.map((m) => (
                                <MessageBubble key={m.id} message={m} />
                            ))
                        )}
                        {conversation.visitor_typing && claimedByMe && (
                            <div className="flex items-center gap-1.5 self-start rounded-full bg-muted px-3 py-1.5 text-xs text-muted-foreground">
                                <span className="flex gap-0.5">
                                    {[0, 150, 300].map((d) => (
                                        <span
                                            key={d}
                                            className="block size-1.5 animate-pulse rounded-full bg-muted-foreground/50"
                                            style={{ animationDelay: `${d}ms` }}
                                        />
                                    ))}
                                </span>
                                {t('Visitor is typing…')}
                            </div>
                        )}
                        <div ref={messagesEndRef} />
                    </div>

                    {/* Composer */}
                    {claimedByMe ? (
                        <div
                            className={`border-t p-3 ${replyMode === 'note' ? 'bg-amber-50/30' : ''}`}
                        >
                            <form
                                onSubmit={sendReply}
                                className="flex flex-col gap-2"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <ToggleGroup
                                        type="single"
                                        value={replyMode}
                                        onValueChange={(v) => {
                                            if (v) {
                                                setReplyMode(
                                                    v as 'reply' | 'note',
                                                );
                                            }
                                        }}
                                        size="sm"
                                    >
                                        <ToggleGroupItem
                                            value="reply"
                                            className="text-xs"
                                        >
                                            <Send className="me-1.5 size-3" />
                                            {t('Reply')}
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="note"
                                            className="text-xs"
                                        >
                                            <NotebookPen className="me-1.5 size-3" />
                                            {t('Note')}
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                    <div className="flex items-center gap-2">
                                        {replyMode === 'reply' && (
                                            <CannedReplyPicker
                                                replies={canned_replies}
                                                onPick={(c) => setReply(c)}
                                            />
                                        )}
                                        <TransferDialog
                                            teammates={teammates}
                                            onTransfer={transfer}
                                            busy={busy}
                                        />
                                    </div>
                                </div>
                                <Textarea
                                    value={reply}
                                    onChange={(e) =>
                                        onReplyChange(e.target.value)
                                    }
                                    onKeyDown={onReplyKeydown}
                                    placeholder={
                                        replyMode === 'note'
                                            ? t(
                                                  'Internal note (only visible to other operators)…',
                                              )
                                            : t(
                                                  'Reply to the visitor… (Cmd / Ctrl + Enter to send)',
                                              )
                                    }
                                    rows={2}
                                    disabled={busy}
                                    autoFocus
                                />
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-xs text-muted-foreground">
                                        {replyMode === 'note'
                                            ? t(
                                                  'The visitor will not see this. Other operators will.',
                                              )
                                            : t(
                                                  "Bot is paused while you're in this chat.",
                                              )}
                                    </p>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={busy || !reply.trim()}
                                    >
                                        {replyMode === 'note' ? (
                                            <NotebookPen className="me-1.5 size-3.5" />
                                        ) : (
                                            <Send className="me-1.5 size-3.5" />
                                        )}
                                        {replyMode === 'note'
                                            ? t('Save note')
                                            : t('Send')}
                                    </Button>
                                </div>
                            </form>
                        </div>
                    ) : claimedByOther ? (
                        <div className="border-t border-dashed p-3 text-center text-xs text-muted-foreground">
                            {t(':name is in this chat. Read-only for you.', {
                                name: conversation.claimed_by!.name,
                            })}
                        </div>
                    ) : conversation.human_requested_at && can_takeover ? (
                        <div className="border-t border-amber-300 bg-amber-50 p-3 text-center text-xs text-amber-800">
                            {t('Visitor is waiting. Click')}{' '}
                            <strong>{t('Claim')}</strong>{' '}
                            {t('to take this conversation.')}
                        </div>
                    ) : null}
                </Card>

                {/* Right pane: context panel (desktop) */}
                {contextOpen && (
                    <div className="hidden w-80 shrink-0 overflow-y-auto md:block">
                        {contextPanel}
                    </div>
                )}
            </div>

            {/* Mobile sheet for context panel */}
            <Sheet open={mobileContextOpen} onOpenChange={setMobileContextOpen}>
                <SheetContent
                    side="right"
                    className="w-full max-w-[420px] overflow-y-auto p-0 sm:max-w-[420px]"
                >
                    <SheetHeader className="border-b">
                        <SheetTitle>{t('Conversation details')}</SheetTitle>
                    </SheetHeader>
                    <div className="p-4">{contextPanel}</div>
                </SheetContent>
            </Sheet>
        </AppLayout>
    );
}

function MessageBubble({ message }: { message: Msg }) {
    const { t } = useT();
    const isUser = message.role === 'user';
    const isHuman = message.is_human || message.role === 'human-agent';
    const isNote = message.is_note === true;

    if (isNote) {
        return (
            <div className="flex flex-col items-center">
                <div
                    className={`w-full max-w-[88%] rounded-md border px-3 py-2 text-xs ${
                        message.is_system
                            ? 'border-slate-200 bg-slate-50 text-slate-700'
                            : 'border-amber-300/60 bg-amber-50 text-amber-900'
                    }`}
                >
                    <p className="mb-0.5 text-[9px] font-semibold tracking-wider uppercase opacity-70">
                        {message.is_system ? t('System') : t('Internal note')}
                    </p>
                    <p className="leading-relaxed whitespace-pre-wrap">
                        {message.content}
                    </p>
                </div>
                <p className="mt-1 text-[10px] text-muted-foreground">
                    {fmtTime(message.created_at)}
                </p>
            </div>
        );
    }

    return (
        <div
            className={`flex flex-col ${isUser ? 'items-end' : 'items-start'}`}
        >
            <div
                className={`max-w-[85%] rounded-2xl px-4 py-2.5 ${
                    isUser
                        ? 'bg-foreground text-background'
                        : isHuman
                          ? 'border border-emerald-500/30 bg-emerald-500/5'
                          : 'bg-muted'
                }`}
            >
                {isHuman && (
                    <p className="mb-1 text-[10px] font-semibold tracking-wider text-emerald-700 uppercase dark:text-emerald-400">
                        {t('Operator')}
                    </p>
                )}
                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                    {message.content}
                </p>
                {message.citations && message.citations.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-2 text-[11px] opacity-75">
                        <span>{t('Sources:')}</span>
                        {message.citations.map((c, i) => (
                            <a
                                key={i}
                                href={c.url ?? '#'}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline"
                            >
                                [{c.id}]
                            </a>
                        ))}
                    </div>
                )}
            </div>
            <p className="mt-1 px-2 text-[10px] text-muted-foreground">
                {fmtTime(message.created_at)}
                {message.confidence !== null && !isUser && !isHuman && (
                    <span
                        className={`ms-2 ${message.confidence < 0.6 ? 'text-amber-600' : ''}`}
                    >
                        {t('· confidence :pct%', {
                            pct: (message.confidence * 100).toFixed(0),
                        })}
                    </span>
                )}
            </p>
        </div>
    );
}
