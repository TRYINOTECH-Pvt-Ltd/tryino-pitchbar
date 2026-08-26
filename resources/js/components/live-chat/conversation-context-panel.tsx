import {
    ExternalLink,
    Globe,
    Mail,
    MessageSquare,
    Route,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
    Timer,
    UserRound,
} from 'lucide-react';
import { LeadScoreBadge } from '@/components/lead-score-badge';
import { useT } from '@/lib/i18n';
import { TagPicker } from './tag-picker';
import type { TagOption } from './tag-picker';

type LeadCard = {
    id: string;
    email: string | null;
    name: string | null;
    phone: string | null;
};

export type TrajectoryEntry = {
    url: string;
    title: string | null;
    referrer: string | null;
    viewed_at: string | null;
    in_this_conversation: boolean;
};

export type ContextPanelProps = {
    visitor: {
        anon: string | null;
        lang: string | null;
        is_returning: boolean;
        page_url: string | null;
    };
    lead: LeadCard | null;
    appliedTags: TagOption[];
    availableTags: TagOption[];
    onAttachTag: (tagId: string) => void;
    onDetachTag: (tagId: string) => void;
    satisfaction: 'positive' | 'negative' | null;
    satisfactionAt: string | null;
    satisfactionComment: string | null;
    sla: {
        label: string;
        value: string;
    } | null;
    conversationStartedAt: string | null;
    leadScore?: number;
    leadScoreBucket?: 'low' | 'medium' | 'high';
    leadScoreReasons?: string[];
    trajectory?: TrajectoryEntry[];
};

/**
 * Right-rail context for the conversation thread. Mirrors the shape of
 * Intercom / Crisp / Tidio's "user details" sidebar — every signal an
 * operator wants while a chat is live, in the order they look for it.
 *
 * Sections collapse gracefully: a brand-new conversation with no lead,
 * no tags, no rating still renders cleanly. Each section is its own
 * card so the panel doesn't feel like a wall of fields.
 */
export function ConversationContextPanel({
    visitor,
    lead,
    appliedTags,
    availableTags,
    onAttachTag,
    onDetachTag,
    satisfaction,
    satisfactionAt,
    satisfactionComment,
    sla,
    conversationStartedAt,
    leadScore,
    leadScoreBucket,
    leadScoreReasons,
    trajectory,
}: ContextPanelProps) {
    const { t } = useT();

    return (
        <div className="flex flex-col gap-4">
            <Section
                icon={<UserRound className="size-3.5" />}
                title={t('Visitor')}
            >
                <dl className="grid gap-1.5 text-xs">
                    <Row label={t('ID')}>
                        <code className="font-mono">
                            {visitor.anon?.slice(0, 16) ?? t('anonymous')}
                        </code>
                    </Row>
                    {visitor.lang && (
                        <Row label={t('Language')}>
                            {visitor.lang.toUpperCase()}
                        </Row>
                    )}
                    <Row label={t('Status')}>
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className={`size-1.5 rounded-full ${visitor.is_returning ? 'bg-violet-500' : 'bg-emerald-500'}`}
                            />
                            {visitor.is_returning ? t('Returning') : t('New')}
                        </span>
                    </Row>
                    {conversationStartedAt && (
                        <Row label={t('Started')}>
                            <span title={conversationStartedAt}>
                                {relTime(conversationStartedAt, t)}
                            </span>
                        </Row>
                    )}
                    {visitor.page_url && (
                        <Row label={t('Page')}>
                            <a
                                href={visitor.page_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex max-w-full items-center gap-1 truncate text-primary hover:underline"
                            >
                                <Globe className="size-3 shrink-0" />
                                <span className="truncate">
                                    {prettyHost(visitor.page_url)}
                                </span>
                                <ExternalLink className="size-3 shrink-0" />
                            </a>
                        </Row>
                    )}
                </dl>
            </Section>

            {lead && (
                <Section
                    icon={<Mail className="size-3.5" />}
                    title={t('Captured lead')}
                >
                    <dl className="grid gap-1.5 text-xs">
                        {lead.email && (
                            <Row label={t('Email')}>
                                <a
                                    href={`mailto:${lead.email}`}
                                    className="font-mono text-primary hover:underline"
                                >
                                    {lead.email}
                                </a>
                            </Row>
                        )}
                        {lead.name && <Row label={t('Name')}>{lead.name}</Row>}
                        {lead.phone && (
                            <Row label={t('Phone')}>
                                <a
                                    href={`tel:${lead.phone}`}
                                    className="font-mono text-primary hover:underline"
                                >
                                    {lead.phone}
                                </a>
                            </Row>
                        )}
                    </dl>
                </Section>
            )}

            <Section
                icon={<MessageSquare className="size-3.5" />}
                title={t('Tags')}
            >
                <TagPicker
                    applied={appliedTags}
                    available={availableTags}
                    onAttach={onAttachTag}
                    onDetach={onDetachTag}
                />
            </Section>

            {satisfaction !== null && (
                <Section
                    icon={
                        satisfaction === 'positive' ? (
                            <ThumbsUp className="size-3.5" />
                        ) : (
                            <ThumbsDown className="size-3.5" />
                        )
                    }
                    title={t('Visitor rating')}
                >
                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-1.5 text-xs">
                            {satisfaction === 'positive' ? (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-800">
                                    <ThumbsUp className="size-3" />
                                    {t('Positive')}
                                </span>
                            ) : (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-800">
                                    <ThumbsDown className="size-3" />
                                    {t('Negative')}
                                </span>
                            )}
                            {satisfactionAt && (
                                <span className="text-muted-foreground">
                                    · {relTime(satisfactionAt, t)}
                                </span>
                            )}
                        </div>
                        {satisfactionComment && (
                            <blockquote className="rounded-md border-s-2 border-foreground/20 bg-muted/50 p-2 text-xs text-muted-foreground italic">
                                "{satisfactionComment}"
                            </blockquote>
                        )}
                    </div>
                </Section>
            )}

            {sla && (
                <Section
                    icon={<Timer className="size-3.5" />}
                    title={t('Timing')}
                >
                    <dl className="grid gap-1.5 text-xs">
                        <Row label={sla.label}>{sla.value}</Row>
                    </dl>
                </Section>
            )}

            {typeof leadScore === 'number' && leadScore > 0 && (
                <Section
                    icon={<Sparkles className="size-3.5" />}
                    title={t('Lead score')}
                >
                    <div className="flex flex-col gap-2">
                        <div className="flex items-center gap-2">
                            <LeadScoreBadge
                                score={leadScore}
                                bucket={leadScoreBucket ?? null}
                                reasons={leadScoreReasons ?? null}
                            />
                            <span className="text-xs text-muted-foreground">
                                {t(
                                    'Auto-computed from browsing + chat signals',
                                )}
                            </span>
                        </div>
                        {leadScoreReasons && leadScoreReasons.length > 0 && (
                            <ul className="ml-1 grid list-disc gap-0.5 pl-3 text-[11px] text-muted-foreground">
                                {leadScoreReasons.map((reason, i) => (
                                    <li key={i}>{reason}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                </Section>
            )}

            {trajectory && trajectory.length > 0 && (
                <Section
                    icon={<Route className="size-3.5" />}
                    title={t('Visitor trajectory')}
                >
                    <ol className="grid gap-1.5 text-xs">
                        {trajectory.map((view, index) => (
                            <li
                                key={`${view.url}-${index}`}
                                className={`flex flex-col gap-0.5 rounded-md border px-2 py-1.5 ${
                                    view.in_this_conversation
                                        ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-800 dark:bg-emerald-900/20'
                                        : 'border-border bg-muted/30'
                                }`}
                            >
                                <a
                                    href={view.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 truncate font-medium text-foreground hover:underline"
                                >
                                    {view.title ?? view.url}
                                    <ExternalLink className="size-3 shrink-0 opacity-60" />
                                </a>
                                {view.title && (
                                    <p className="truncate text-[10px] text-muted-foreground">
                                        {view.url}
                                    </p>
                                )}
                                {view.viewed_at && (
                                    <p className="text-[10px] text-muted-foreground">
                                        {relTime(view.viewed_at, t)}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                </Section>
            )}
        </div>
    );
}

function Section({
    icon,
    title,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2 rounded-md border bg-card p-3">
            <h3 className="flex items-center gap-1.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                {icon}
                {title}
            </h3>
            {children}
        </section>
    );
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-[80px_1fr] items-baseline gap-2">
            <dt className="text-[11px] tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="min-w-0 truncate">{children}</dd>
        </div>
    );
}

function relTime(
    iso: string,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    const ms = Date.now() - new Date(iso).getTime();
    const sec = Math.max(1, Math.round(ms / 1000));

    if (sec < 60) {
        return t('just now');
    }

    const min = Math.round(sec / 60);

    if (min < 60) {
        return t(':count m ago', { count: min });
    }

    const hour = Math.round(min / 60);

    if (hour < 24) {
        return t(':count h ago', { count: hour });
    }

    const day = Math.round(hour / 24);

    return t(':count d ago', { count: day });
}

function prettyHost(url: string): string {
    try {
        const u = new URL(url);

        return u.host + u.pathname;
    } catch {
        return url;
    }
}
