import { Head, Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowRight,
    ArrowUpRight,
    BarChart3,
    Beaker,
    BookOpen,
    Check,
    Clipboard,
    Database,
    Globe,
    Inbox,
    MessageSquare,
    MessagesSquare,
    Palette,
    Play,
    Plug,
    Sparkles,
    Trash2,
    Workflow,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    customize as agentCustomize,
    destroy as destroyAgent,
    edit as editAgent,
    index as agentsIndex,
    playground as agentPlayground,
    publish as publishAgent,
    show as showAgent,
    vertical as agentVertical,
} from '@/routes/agents';
import { index as agentBehaviorIndex } from '@/routes/agents/behavior';
import { index as agentConversationsIndex } from '@/routes/agents/conversations';
import { index as agentCtasIndex } from '@/routes/agents/ctas';
import { index as agentCuratedIndex } from '@/routes/agents/curated';
import { index as agentExperimentsIndex } from '@/routes/agents/experiments';
import { index as agentKnowledgeIndex } from '@/routes/agents/knowledge';
import { index as agentLeadsIndex } from '@/routes/agents/leads';
import { index as agentSourcesIndex } from '@/routes/agents/sources';
import type { BreadcrumbItem } from '@/types';
import { index as agentMcpIndex } from '@/routes/agents/mcp';

type Agent = {
    id: string;
    name: string;
    language_default: string;
    is_published: boolean;
    confidence_threshold: number;
    system_prompt: string | null;
    site_type?: string | null;
};

type Setup = {
    sources_indexed: number;
    sources_total: number;
    has_messages: boolean;
};

type Embed = {
    widget_url: string;
    snippet: string;
};

type Props = {
    agent: Agent;
    setup: Setup;
    embed: Embed;
};

type NavTile = {
    title: string;
    description: string;
    href: string;
    icon: LucideIcon;
    primary?: boolean;
};

const navTiles = (
    id: string,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): NavTile[] => [
    {
        title: t('Knowledge sources'),
        description: t('Add URLs or upload files. The crawler indexes them.'),
        href: agentSourcesIndex({ agent: id }).url,
        icon: Database,
        primary: true,
    },
    {
        title: t('Knowledge (what AI sees)'),
        description: t(
            'Inspect every page and chunk we indexed — confirm the crawl pulled real content.',
        ),
        href: agentKnowledgeIndex({ agent: id }).url,
        icon: BookOpen,
        primary: true,
    },
    {
        title: t('Conversations'),
        description: t(
            'Read every visitor session — what they asked, what the bot said, when it happened.',
        ),
        href: agentConversationsIndex({ agent: id }).url,
        icon: MessagesSquare,
        primary: true,
    },
    {
        title: t('Leads'),
        description: t(
            'See every lead captured by this agent and jump straight into the matching record.',
        ),
        href: agentLeadsIndex({ agent: id }).url,
        icon: Inbox,
        primary: true,
    },
    {
        title: t('Playground'),
        description: t('Chat with the agent (does not count toward billing).'),
        href: agentPlayground({ agent: id }).url,
        icon: Play,
        primary: true,
    },
    {
        title: t('Curated answers'),
        description: t(
            'Predefined responses that bypass the LLM for matching questions.',
        ),
        href: agentCuratedIndex({ agent: id }).url,
        icon: Sparkles,
    },
    {
        title: t('Behavior triggers'),
        description: t(
            'Open the bar on exit-intent, idle, scroll, time, returning, UTM.',
        ),
        href: agentBehaviorIndex({ agent: id }).url,
        icon: Workflow,
    },
    {
        title: t('CTAs'),
        description: t(
            'Buttons that surface in conversations to drive demos / signups.',
        ),
        href: agentCtasIndex({ agent: id }).url,
        icon: BarChart3,
    },
    {
        title: t('Customize appearance'),
        description: t(
            'Persona, tone, colors, guardrails — with live preview.',
        ),
        href: agentCustomize({ agent: id }).url,
        icon: Palette,
    },
    {
        title: t('Site type'),
        description: t(
            'Auto-detected vertical that drives starter prompts and capabilities.',
        ),
        href: agentVertical({ agent: id }).url,
        icon: Globe,
    },
    {
        title: t('A/B experiments'),
        description: t(
            'Test persona / CTA / trigger variations on a slice of visitors.',
        ),
        href: agentExperimentsIndex({ agent: id }).url,
        icon: Beaker,
    },
    {
        title: t('MCP integrations'),
        description: t(
            'Plug the agent into your CRM, calendar, ticketing, or internal APIs via Model Context Protocol — the bot calls tools mid-conversation.',
        ),
        href: agentMcpIndex({ agent: id }).url,
        icon: Plug,
    },
];

function AgentStatusBadge({
    published,
    className,
}: {
    published: boolean;
    className?: string;
}) {
    const { t } = useT();

    return (
        <span
            className={cn(
                'inline-flex items-center gap-2 rounded-md border px-2.5 py-1 text-xs font-medium',
                published
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                    : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
                className,
            )}
        >
            <span
                className={cn(
                    'inline-block size-1.5 rounded-full',
                    published ? 'bg-emerald-500' : 'bg-amber-500',
                )}
            ></span>
            {published ? t('Live') : t('Draft')}
        </span>
    );
}

function SetupStepCard({
    step,
    done,
    title,
    description,
    className,
}: {
    step: number;
    done: boolean;
    title: string;
    description: string;
    className?: string;
}) {
    return (
        <div className={cn('flex h-full gap-3 px-4 py-4', className)}>
            <span
                className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    done
                        ? 'bg-emerald-500 text-white'
                        : 'border border-muted-foreground/25 bg-background text-muted-foreground',
                )}
            >
                {done ? <Check className="size-3.5" /> : step}
            </span>
            <div className="min-w-0">
                <p className="text-sm font-medium text-foreground">{title}</p>
                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    {description}
                </p>
            </div>
        </div>
    );
}

function ActionTile({
    tile,
    prominent = false,
}: {
    tile: NavTile;
    prominent?: boolean;
}) {
    const Icon = tile.icon;

    return (
        <Link href={tile.href} prefetch className="group block h-full">
            <Card
                className={cn(
                    'h-full p-4 transition duration-200 hover:-translate-y-0.5 hover:border-foreground/20 hover:shadow-lg',
                    prominent
                        ? 'border-foreground/15 bg-[linear-gradient(180deg,hsl(var(--card))_0%,color-mix(in_oklab,hsl(var(--muted))_30%,transparent)_100%)]'
                        : 'bg-card',
                )}
            >
                <div className="flex items-start justify-between gap-3">
                    <span
                        className={cn(
                            'inline-flex rounded-xl p-2 transition',
                            prominent
                                ? 'bg-foreground/5 text-foreground'
                                : 'bg-muted text-muted-foreground group-hover:bg-foreground/5 group-hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                    </span>
                    <ArrowUpRight className="size-4 text-muted-foreground transition group-hover:text-foreground" />
                </div>
                <h3 className="mt-4 text-sm font-semibold text-foreground">
                    {tile.title}
                </h3>
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    {tile.description}
                </p>
            </Card>
        </Link>
    );
}

function SnapshotMetric({
    label,
    value,
    helper,
    className,
}: {
    label: string;
    value: string;
    helper: string;
    className?: string;
}) {
    return (
        <div className={cn('min-w-0 px-4 py-4', className)}>
            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-2 text-2xl font-semibold tracking-tight text-foreground">
                {value}
            </p>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                {helper}
            </p>
        </div>
    );
}

/**
 * Amber nudge banner that surfaces when an agent has no site_type
 * resolved yet. Without a site type the LLM never receives the
 * vertical preset's marker-emission instruction and replies in raw
 * text. Dismissible per-agent via localStorage so a customer who
 * deliberately wants `generic` isn't nagged on every visit.
 */
function SiteTypeNudgeBanner({ agent }: { agent: Agent }) {
    const { t } = useT();
    const storageKey = `pb:site_type_nudge_dismissed:${agent.id}`;
    const [dismissed, setDismissed] = useState<boolean>(() => {
        try {
            return window.localStorage.getItem(storageKey) === '1';
        } catch {
            return false;
        }
    });

    if (
        agent.site_type !== null &&
        agent.site_type !== undefined &&
        agent.site_type !== ''
    ) {
        return null;
    }

    if (dismissed) {
        return null;
    }

    return (
        <div className="mx-4 mt-4 flex flex-wrap items-start gap-3 rounded-md border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm dark:border-amber-700 dark:bg-amber-900/20">
            <Sparkles className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-300" />
            <div className="min-w-0 flex-1">
                <p className="font-medium text-amber-900 dark:text-amber-100">
                    {t('Pick a site type to unlock rich messages')}
                </p>
                <p className="mt-0.5 text-xs leading-5 text-amber-800/90 dark:text-amber-200/80">
                    {t(
                        'Without a site type the agent replies in raw text. Pick ecommerce, saas, marketing, etc. so the LLM can emit product cards, pricing tables, and CTA buttons.',
                    )}
                </p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Button variant="outline" size="sm" asChild>
                    <Link href={agentVertical({ agent: agent.id }).url}>
                        {t('Set site type')}
                    </Link>
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        try {
                            window.localStorage.setItem(storageKey, '1');
                        } catch {
                            // best-effort; ignore localStorage failures
                        }

                        setDismissed(true);
                    }}
                    aria-label={t('Dismiss')}
                >
                    {t('Dismiss')}
                </Button>
            </div>
        </div>
    );
}

export default function ShowAgent({ agent, setup, embed }: Props) {
    const { t, tChoice } = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
    ];

    const lang = (agent.language_default ?? 'en').toUpperCase();
    const tiles = navTiles(agent.id, t);
    const primaryTiles = tiles.filter((tile) => tile.primary === true);
    const secondaryTiles = tiles.filter((tile) => tile.primary !== true);
    const [copied, setCopied] = useState(false);
    const [isPublishing, setIsPublishing] = useState(false);

    const sourcesDone = setup.sources_indexed > 0;
    const playgroundDone = setup.has_messages;
    const publishedDone = agent.is_published;
    const completedSteps = [sourcesDone, playgroundDone, publishedDone].filter(
        Boolean,
    ).length;
    const completionPercent = (completedSteps / 3) * 100;
    const confidencePercent = Math.round(agent.confidence_threshold * 100);
    const stepsLeft = 3 - completedSteps;
    const readinessLabel =
        completedSteps === 3
            ? t('Ready for installation.')
            : tChoice(
                  ':count setup step left|:count setup steps left',
                  stepsLeft,
                  { count: stepsLeft },
              );
    const nextAction: {
        title: string;
        description: string;
        type: 'link' | 'publish';
        href?: string;
    } = !sourcesDone
        ? {
              title: t('Add your first source'),
              description: t(
                  'Index at least one URL or file so the agent has real knowledge to answer from.',
              ),
              type: 'link',
              href: agentSourcesIndex({ agent: agent.id }).url,
          }
        : !playgroundDone
          ? {
                title: t('Run a playground check'),
                description: t(
                    'Ask a few realistic questions and confirm the tone before publishing.',
                ),
                type: 'link',
                href: agentPlayground({ agent: agent.id }).url,
            }
          : !publishedDone
            ? {
                  title: t('Publish this agent'),
                  description: t(
                      'The setup is ready. Publish to unlock the install snippet and go live.',
                  ),
                  type: 'publish',
              }
            : {
                  title: t('Review live conversations'),
                  description: t(
                      'Watch real visitor sessions and keep improving the live experience.',
                  ),
                  type: 'link',
                  href: agentConversationsIndex({ agent: agent.id }).url,
              };

    const copySnippet = async () => {
        try {
            await navigator.clipboard.writeText(embed.snippet);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        } catch {
            // ignored
        }
    };

    const publish = () => {
        if (isPublishing) {
            return;
        }

        setIsPublishing(true);

        router.post(
            publishAgent.url({ agent: agent.id }),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsPublishing(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={agent.name} />
            <div className="flex min-h-0 flex-1 flex-col bg-card">
                <div className="flex min-h-10 flex-wrap items-center gap-2 border-b px-3 py-2 sm:py-1.5">
                    <AgentStatusBadge published={agent.is_published} />
                    <span className="inline-flex h-7 items-center rounded-md border bg-background px-2.5 text-xs font-normal text-muted-foreground">
                        {lang}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-background px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':indexed/:total indexed', {
                            indexed: setup.sources_indexed,
                            total: setup.sources_total,
                        })}
                    </span>

                    <div className="ms-auto flex w-full flex-wrap items-center justify-end gap-2 sm:w-auto">
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={editAgent.url({ agent: agent.id })}
                                prefetch
                            >
                                {t('Settings')}
                            </Link>
                        </Button>
                        <Button
                            size="sm"
                            variant={agent.is_published ? 'outline' : 'default'}
                            onClick={publish}
                            disabled={isPublishing}
                        >
                            {isPublishing ? (
                                <>
                                    <Check className="size-3.5" />
                                    {t('Working...')}
                                </>
                            ) : agent.is_published ? (
                                t('Re-publish')
                            ) : (
                                t('Publish')
                            )}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                // Sonner-based confirm: a sticky toast with
                                // explicit Delete + Cancel actions. Less
                                // jarring than the native browser confirm()
                                // and matches the rest of the app's UX.
                                toast.warning(
                                    t('Delete the agent ":name"?', {
                                        name: agent.name,
                                    }),
                                    {
                                        description: t(
                                            'This removes the agent, its knowledge sources, conversations, and captured leads. This cannot be undone.',
                                        ),
                                        duration: 12000,
                                        action: {
                                            label: t('Delete'),
                                            onClick: () =>
                                                router.delete(
                                                    destroyAgent.url({
                                                        agent: agent.id,
                                                    }),
                                                ),
                                        },
                                        cancel: {
                                            label: t('Cancel'),
                                            onClick: () => {
                                                /* dismiss */
                                            },
                                        },
                                    },
                                )
                            }
                            className="border-destructive/40 text-destructive hover:border-destructive hover:bg-destructive/10 hover:text-destructive"
                        >
                            <Trash2 className="size-3.5" />
                            {t('Delete')}
                        </Button>
                    </div>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <SiteTypeNudgeBanner agent={agent} />

                    <div className="grid gap-4 p-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.95fr)]">
                        <div className="grid gap-4">
                            <Card className="overflow-hidden p-0">
                                <div className="border-b px-4 py-4 sm:px-5">
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div className="min-w-0 flex-1">
                                            <span className="inline-flex items-center rounded-full border bg-muted/30 px-3 py-1 text-[11px] font-medium text-muted-foreground">
                                                {t('Agent workspace')}
                                            </span>
                                            <h1 className="mt-4 text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                                                {agent.name}
                                            </h1>
                                            <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                                                {t(
                                                    'Train the agent, review what it sees, test real prompts, and publish when the setup is ready for visitors.',
                                                )}
                                            </p>
                                            <div className="mt-4 flex flex-wrap gap-2">
                                                <AgentStatusBadge
                                                    published={
                                                        agent.is_published
                                                    }
                                                />
                                                <span className="inline-flex items-center rounded-md border bg-background px-2.5 py-1 text-xs text-muted-foreground">
                                                    {t(
                                                        ':lang default language',
                                                        { lang },
                                                    )}
                                                </span>
                                                <span className="inline-flex items-center rounded-md border bg-background px-2.5 py-1 text-xs text-muted-foreground">
                                                    {t(
                                                        ':percent% confidence threshold',
                                                        {
                                                            percent:
                                                                confidencePercent,
                                                        },
                                                    )}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="max-w-full min-w-[240px] rounded-2xl border bg-muted/25 p-4">
                                            <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                                {t('Setup progress')}
                                            </p>
                                            <div className="mt-3 flex items-end justify-between gap-3">
                                                <p className="text-3xl font-semibold tracking-tight text-foreground tabular-nums">
                                                    {completedSteps}/3
                                                </p>
                                                <p className="max-w-[9rem] text-end text-xs text-muted-foreground">
                                                    {readinessLabel}
                                                </p>
                                            </div>
                                            <div className="mt-3 h-2 rounded-full bg-muted">
                                                <div
                                                    className="h-2 rounded-full bg-foreground"
                                                    style={{
                                                        width: `${completionPercent}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="grid gap-0 md:grid-cols-3">
                                    <SetupStepCard
                                        step={1}
                                        done={sourcesDone}
                                        title={t('Train with real knowledge')}
                                        description={
                                            sourcesDone
                                                ? tChoice(
                                                      'Indexed :indexed of :total source successfully.|Indexed :indexed of :total sources successfully.',
                                                      setup.sources_total,
                                                      {
                                                          indexed:
                                                              setup.sources_indexed,
                                                          total: setup.sources_total,
                                                      },
                                                  )
                                                : t(
                                                      'Add at least one URL or file so the agent can answer from real content.',
                                                  )
                                        }
                                        className="md:border-e"
                                    />
                                    <SetupStepCard
                                        step={2}
                                        done={playgroundDone}
                                        title={t('Validate in playground')}
                                        description={
                                            playgroundDone
                                                ? t(
                                                      'The agent has already been tested in the playground.',
                                                  )
                                                : t(
                                                      'Run a few realistic prompts to check tone, accuracy, and fallback behavior.',
                                                  )
                                        }
                                        className="md:border-e"
                                    />
                                    <SetupStepCard
                                        step={3}
                                        done={publishedDone}
                                        title={t('Go live')}
                                        description={
                                            publishedDone
                                                ? t(
                                                      'Published and ready for installation on your site.',
                                                  )
                                                : t(
                                                      'Publish when the setup looks correct so you can install the widget.',
                                                  )
                                        }
                                    />
                                </div>

                                <div className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium text-foreground">
                                            {nextAction.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {nextAction.description}
                                        </p>
                                    </div>

                                    {nextAction.type === 'publish' ? (
                                        <Button
                                            size="sm"
                                            onClick={publish}
                                            disabled={isPublishing}
                                        >
                                            {isPublishing
                                                ? t('Publishing...')
                                                : t('Publish agent')}
                                        </Button>
                                    ) : (
                                        <Button asChild size="sm">
                                            <Link
                                                href={nextAction.href ?? '#'}
                                                prefetch
                                            >
                                                {nextAction.title}
                                                <ArrowRight className="size-3.5" />
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </Card>

                            <div>
                                <div className="mb-3">
                                    <h2 className="text-sm font-medium text-foreground">
                                        {t('Core workflow')}
                                    </h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t(
                                            "The main places you'll use to train, test, and operate this agent day to day.",
                                        )}
                                    </p>
                                </div>
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    {primaryTiles.map((tile) => (
                                        <ActionTile
                                            key={tile.title}
                                            tile={tile}
                                            prominent
                                        />
                                    ))}
                                </div>
                            </div>

                            <div>
                                <div className="mb-3">
                                    <h2 className="text-sm font-medium text-foreground">
                                        {t('Optimize and grow')}
                                    </h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t(
                                            'Refine answers, triggers, presentation, and experiments from the same workspace.',
                                        )}
                                    </p>
                                </div>
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    {secondaryTiles.map((tile) => (
                                        <ActionTile
                                            key={tile.title}
                                            tile={tile}
                                        />
                                    ))}
                                </div>
                            </div>

                            <Card className="p-4">
                                <div className="flex items-center gap-2">
                                    <MessageSquare className="size-4 text-muted-foreground" />
                                    <p className="text-sm font-medium text-foreground">
                                        {t('System prompt')}
                                    </p>
                                </div>
                                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                    {t(
                                        'This is the instruction layer the model sees before indexed knowledge and live visitor messages are added.',
                                    )}
                                </p>
                                <pre className="mt-3 min-h-28 rounded-xl border bg-muted/30 p-3 text-xs leading-6 whitespace-pre-wrap text-foreground">
                                    {agent.system_prompt ??
                                        t('(none yet — set one in Settings)')}
                                </pre>
                            </Card>
                        </div>

                        <div className="grid gap-4">
                            <Card className="overflow-hidden p-0">
                                <div className="border-b px-4 py-4">
                                    <h2 className="text-sm font-medium text-foreground">
                                        {t('Launch snapshot')}
                                    </h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t(
                                            'Readiness, deployment state, and agent behavior at a glance.',
                                        )}
                                    </p>
                                </div>
                                <div className="grid grid-cols-2 gap-0">
                                    <SnapshotMetric
                                        label={t('Knowledge')}
                                        value={
                                            setup.sources_total > 0
                                                ? `${setup.sources_indexed}/${setup.sources_total}`
                                                : '0'
                                        }
                                        helper={
                                            setup.sources_total > 0
                                                ? t('Indexed sources')
                                                : t('Sources added so far')
                                        }
                                        className="border-e border-b"
                                    />
                                    <SnapshotMetric
                                        label={t('Playground')}
                                        value={
                                            playgroundDone
                                                ? t('Ready')
                                                : t('Pending')
                                        }
                                        helper={
                                            playgroundDone
                                                ? t(
                                                      'Tested with sample prompts',
                                                  )
                                                : t('Needs a validation pass')
                                        }
                                        className="border-b"
                                    />
                                    <SnapshotMetric
                                        label={t('Publishing')}
                                        value={
                                            publishedDone
                                                ? t('Live')
                                                : t('Draft')
                                        }
                                        helper={
                                            publishedDone
                                                ? t('Widget can be installed')
                                                : t('Private until published')
                                        }
                                        className="border-e"
                                    />
                                    <SnapshotMetric
                                        label={t('Confidence')}
                                        value={`${confidencePercent}%`}
                                        helper={t(
                                            'Minimum confidence threshold',
                                        )}
                                    />
                                </div>
                            </Card>

                            {agent.is_published ? (
                                <Card className="overflow-hidden p-0">
                                    <div className="border-b px-4 py-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <h2 className="text-sm font-medium text-foreground">
                                                    {t('Embed on your site')}
                                                </h2>
                                                <div className="mt-1 grid gap-1 text-xs text-muted-foreground">
                                                    <p>
                                                        {t(
                                                            'Copy this one-line snippet and paste it just before the closing',
                                                        )}{' '}
                                                        <code>
                                                            &lt;/body&gt;
                                                        </code>{' '}
                                                        {t(
                                                            'tag on any page where the agent should appear.',
                                                        )}
                                                    </p>
                                                    <p>
                                                        {t(
                                                            'Alternatively place it inside',
                                                        )}{' '}
                                                        <code>
                                                            &lt;head&gt;
                                                        </code>{' '}
                                                        {t(
                                                            '— the script is async + deferred, so it never blocks rendering.',
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                            <AgentStatusBadge published />
                                        </div>
                                    </div>
                                    <div className="px-4 py-4">
                                        <pre className="overflow-x-auto rounded-xl border bg-muted/30 p-3 text-xs leading-6 whitespace-pre-wrap text-foreground">
                                            {embed.snippet}
                                        </pre>
                                        <div className="mt-3 flex flex-wrap items-center gap-2">
                                            <Button
                                                onClick={copySnippet}
                                                variant="outline"
                                                size="sm"
                                            >
                                                {copied ? (
                                                    <>
                                                        <Check className="size-3.5" />
                                                        {t('Copied')}
                                                    </>
                                                ) : (
                                                    <>
                                                        <Clipboard className="size-3.5" />
                                                        {t('Copy snippet')}
                                                    </>
                                                )}
                                            </Button>
                                        </div>
                                        <div className="mt-3 rounded-xl border bg-background px-3 py-2">
                                            <p className="text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                                {t('Loader URL')}
                                            </p>
                                            <p className="mt-1 text-xs break-all text-foreground">
                                                {embed.widget_url}
                                            </p>
                                        </div>
                                    </div>
                                </Card>
                            ) : (
                                <Card className="p-4">
                                    <div className="flex items-center gap-2">
                                        <Globe className="size-4 text-muted-foreground" />
                                        <p className="text-sm font-medium text-foreground">
                                            {t(
                                                'Publish to unlock installation',
                                            )}
                                        </p>
                                    </div>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {t(
                                            'As soon as this agent is published, this panel will switch to the live embed snippet you can paste into your site.',
                                        )}
                                    </p>
                                    <div className="mt-4 rounded-xl border bg-muted/30 p-3">
                                        <p className="text-xs font-medium text-foreground">
                                            {t('Before you launch')}
                                        </p>
                                        <div className="mt-3 space-y-2 text-xs text-muted-foreground">
                                            <div className="flex items-start gap-2">
                                                <span className="mt-1 size-1.5 rounded-full bg-current" />
                                                {t(
                                                    'Index at least one real source.',
                                                )}
                                            </div>
                                            <div className="flex items-start gap-2">
                                                <span className="mt-1 size-1.5 rounded-full bg-current" />
                                                {t(
                                                    'Test answers in playground with realistic prompts.',
                                                )}
                                            </div>
                                            <div className="flex items-start gap-2">
                                                <span className="mt-1 size-1.5 rounded-full bg-current" />
                                                {t(
                                                    'Confirm the prompt and appearance settings.',
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </Card>
                            )}

                            <Card className="p-4">
                                <div className="flex items-center gap-2">
                                    <Palette className="size-4 text-muted-foreground" />
                                    <p className="text-sm font-medium text-foreground">
                                        {t('Behavior and presentation')}
                                    </p>
                                </div>
                                <div className="mt-4 space-y-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-medium text-foreground">
                                                {t('Voice and prompt')}
                                            </p>
                                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                {agent.system_prompt
                                                    ? t(
                                                          'Custom system instructions are already guiding the model.',
                                                      )
                                                    : t(
                                                          'Still using the default instructions until you define a custom prompt.',
                                                      )}
                                            </p>
                                        </div>
                                        <Link
                                            href={editAgent.url({
                                                agent: agent.id,
                                            })}
                                            prefetch
                                            className="inline-flex items-center gap-1 text-xs font-medium text-foreground hover:text-foreground/80"
                                        >
                                            {t('Settings')}
                                            <ArrowUpRight className="size-3.5" />
                                        </Link>
                                    </div>

                                    <div className="flex items-start justify-between gap-3 border-t pt-4">
                                        <div>
                                            <p className="text-sm font-medium text-foreground">
                                                {t(
                                                    'Appearance and widget feel',
                                                )}
                                            </p>
                                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                {t(
                                                    'Tune colors, persona, and guardrails with a live widget preview.',
                                                )}
                                            </p>
                                        </div>
                                        <Link
                                            href={
                                                agentCustomize({
                                                    agent: agent.id,
                                                }).url
                                            }
                                            prefetch
                                            className="inline-flex items-center gap-1 text-xs font-medium text-foreground hover:text-foreground/80"
                                        >
                                            {t('Customize')}
                                            <ArrowUpRight className="size-3.5" />
                                        </Link>
                                    </div>

                                    <div className="flex items-start justify-between gap-3 border-t pt-4">
                                        <div>
                                            <p className="text-sm font-medium text-foreground">
                                                {t('Leads and conversations')}
                                            </p>
                                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                {t(
                                                    'Jump into live sessions and captured leads once the agent starts talking.',
                                                )}
                                            </p>
                                        </div>
                                        <Link
                                            href={
                                                agentConversationsIndex({
                                                    agent: agent.id,
                                                }).url
                                            }
                                            prefetch
                                            className="inline-flex items-center gap-1 text-xs font-medium text-foreground hover:text-foreground/80"
                                        >
                                            {t('Inbox')}
                                            <ArrowUpRight className="size-3.5" />
                                        </Link>
                                    </div>
                                </div>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
