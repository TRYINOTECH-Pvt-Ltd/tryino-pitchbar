import { Head, router, useForm } from '@inertiajs/react';
import { BookOpen, ExternalLink, Sparkles, Trash2 } from 'lucide-react';
import { useConfirm, usePrompt } from '@/components/confirm-dialog-provider';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { SortableList } from '@/components/sortable-list';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import { index as agentCuratedIndex } from '@/routes/agents/curated';
import type { BreadcrumbItem } from '@/types';

type Answer = {
    id: string;
    question_pattern: string;
    answer: string;
    priority: number;
    enabled: boolean;
    lang: string | null;
    slug: string | null;
    kb_title: string | null;
    kb_published: boolean;
    kb_url: string | null;
    is_suggested: boolean;
};

type Props = {
    agent: { id: string; name: string };
    workspace_slug: string | null;
    answers: Answer[];
};

export default function Curated({ agent, workspace_slug, answers }: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const prompt = usePrompt();
    const form = useForm({
        question_pattern: '',
        answer: '',
        priority: 0,
        kb_title: '',
        kb_published: false,
    });

    const togglePublish = (a: Answer) => {
        router.patch(
            `/app/curated-answers/${a.id}`,
            { kb_published: !a.kb_published },
            { preserveScroll: true, only: ['answers'] },
        );
    };

    const renameKbTitle = (a: Answer, title: string) => {
        router.patch(
            `/app/curated-answers/${a.id}`,
            { kb_title: title },
            { preserveScroll: true, only: ['answers'] },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('Curated answers'),
            href: agentCuratedIndex({ agent: agent.id }).url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · curated`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Curated answers')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t(
                        "Predefined responses that short-circuit the LLM when the visitor's message contains the pattern.",
                    )}
                </p>

                <Card className="max-w-5xl gap-0 overflow-hidden border-border/80">
                    <CardHeader className="border-b bg-muted/20 px-4 py-4 sm:px-5">
                        <div className="flex items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground shadow-xs">
                                <Sparkles className="size-4" />
                            </span>
                            <div className="min-w-0">
                                <CardTitle className="text-sm">
                                    {t('Add a curated answer')}
                                </CardTitle>
                                <CardDescription className="mt-1 text-xs leading-5">
                                    {t(
                                        'Use curated answers for questions that should always return the same exact copy.',
                                    )}
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/app/agents/${agent.id}/curated`, {
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <CardContent className="grid gap-4 px-4 py-4 sm:px-5 sm:py-5">
                            <div className="grid gap-1.5">
                                <Label htmlFor="pattern">
                                    {t("Match if visitor's message contains")}
                                </Label>
                                <Input
                                    id="pattern"
                                    value={form.data.question_pattern}
                                    placeholder={t(
                                        'pricing, refund policy, shipping time',
                                    )}
                                    aria-invalid={Boolean(
                                        form.errors.question_pattern,
                                    )}
                                    onChange={(e) =>
                                        form.setData(
                                            'question_pattern',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Use a short phrase or keyword set the bot should recognize before reaching for the LLM.',
                                    )}
                                </p>
                                <InputError
                                    message={form.errors.question_pattern}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <div className="flex items-center justify-between gap-3">
                                    <Label htmlFor="answer">
                                        {t('Answer')}
                                    </Label>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {t(':count chars', {
                                            count: form.data.answer.length,
                                        })}
                                    </span>
                                </div>
                                <Textarea
                                    id="answer"
                                    rows={5}
                                    value={form.data.answer}
                                    onChange={(e) =>
                                        form.setData('answer', e.target.value)
                                    }
                                    className="min-h-36 resize-y leading-6"
                                    aria-invalid={Boolean(form.errors.answer)}
                                    placeholder={t(
                                        'Our plans start at $49/month and include...',
                                    )}
                                />
                                <InputError message={form.errors.answer} />
                            </div>
                            <div className="grid gap-1.5 border-t pt-4">
                                <div className="flex items-center gap-2">
                                    <BookOpen className="size-4 text-muted-foreground" />
                                    <Label
                                        htmlFor="kb_title"
                                        className="font-medium"
                                    >
                                        {t('Knowledge base article')}
                                    </Label>
                                </div>
                                <Input
                                    id="kb_title"
                                    value={form.data.kb_title}
                                    placeholder={t(
                                        'Optional: page title for the public KB page',
                                    )}
                                    onChange={(e) =>
                                        form.setData('kb_title', e.target.value)
                                    }
                                />
                                <label className="flex cursor-pointer items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.kb_published}
                                        onChange={(e) =>
                                            form.setData(
                                                'kb_published',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        {t(
                                            'Publish to /kb (visible to anyone with the link)',
                                        )}
                                    </span>
                                </label>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'When published, this answer renders at /kb/:slug/<auto-slug> and the agent can cite it via the send_kb_article tool.',
                                        { slug: workspace_slug ?? '<slug>' },
                                    )}
                                </p>
                                <InputError message={form.errors.kb_title} />
                            </div>
                        </CardContent>
                        <CardFooter className="flex flex-col-reverse gap-2 border-t bg-muted/10 px-4 py-4 sm:flex-row sm:justify-end sm:px-5">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="w-full sm:w-auto"
                            >
                                {t('Add answer')}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <Card className="p-4">
                    <h2 className="mb-3 font-medium">
                        {t(
                            'Existing (drag to reorder — top = highest priority)',
                        )}
                    </h2>
                    {answers.length === 0 ? (
                        <EmptyState
                            icon={Sparkles}
                            title={t('No curated answers yet')}
                            description={t(
                                "Curated answers are exact responses the bot returns when a visitor's question matches a pattern — useful for pricing, returns, hours, anything you want answered the same way every time without an LLM call.",
                            )}
                        />
                    ) : (
                        <SortableList
                            items={answers}
                            keyOf={(a) => a.id}
                            onReorder={(orderedIds) =>
                                router.post(
                                    `/app/agents/${agent.id}/curated/reorder`,
                                    { order: orderedIds },
                                    { preserveScroll: true, only: ['answers'] },
                                )
                            }
                        >
                            {(a) => (
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="flex items-center gap-2 text-sm font-medium">
                                            {t('if message contains:')}{' '}
                                            <em>{a.question_pattern}</em>
                                            {a.is_suggested && (
                                                <span className="inline-flex items-center gap-1 rounded bg-amber-500/15 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-400">
                                                    <Sparkles className="size-3" />{' '}
                                                    {t('Suggested')}
                                                </span>
                                            )}
                                            {!a.enabled && !a.is_suggested && (
                                                <span className="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                    {t('Disabled')}
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                            {a.answer}
                                        </p>
                                        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                            <button
                                                type="button"
                                                onClick={() => togglePublish(a)}
                                                className={
                                                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-medium transition ' +
                                                    (a.kb_published
                                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                        : 'border-border bg-muted/30 text-muted-foreground hover:bg-muted')
                                                }
                                                title={
                                                    a.kb_published
                                                        ? t(
                                                              'Click to unpublish from /kb',
                                                          )
                                                        : t(
                                                              'Click to publish to /kb',
                                                          )
                                                }
                                            >
                                                <BookOpen className="size-3" />
                                                {a.kb_published
                                                    ? t('KB published')
                                                    : t('Publish to KB')}
                                            </button>
                                            {a.kb_published && a.kb_url && (
                                                <a
                                                    href={a.kb_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2 py-0.5 font-mono text-[11px] text-muted-foreground hover:bg-muted"
                                                >
                                                    {a.kb_url}
                                                    <ExternalLink className="size-3" />
                                                </a>
                                            )}
                                            {a.kb_published && (
                                                <button
                                                    type="button"
                                                    onClick={async () => {
                                                        const next =
                                                            await prompt({
                                                                title: t(
                                                                    'KB article title',
                                                                ),
                                                                message: t(
                                                                    'KB article title (used as the H1 + page title)',
                                                                ),
                                                                defaultValue:
                                                                    a.kb_title ??
                                                                    '',
                                                                placeholder: t(
                                                                    'e.g. How to reset your password',
                                                                ),
                                                                confirmLabel:
                                                                    t('Save'),
                                                                validate: (
                                                                    v,
                                                                ) =>
                                                                    v.trim() ===
                                                                    ''
                                                                        ? t(
                                                                              'Title cannot be empty.',
                                                                          )
                                                                        : null,
                                                            });

                                                        if (next !== null) {
                                                            renameKbTitle(
                                                                a,
                                                                next,
                                                            );
                                                        }
                                                    }}
                                                    className="rounded-full border border-border bg-card px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-muted"
                                                >
                                                    {a.kb_title
                                                        ? t('Title: ":title"', {
                                                              title: a.kb_title,
                                                          })
                                                        : t('Set KB title')}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-1">
                                        {a.is_suggested && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/app/curated-answers/${a.id}/approve`,
                                                    )
                                                }
                                            >
                                                {t('Approve')}
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={async () => {
                                                const ok = await confirm({
                                                    title: a.is_suggested
                                                        ? t(
                                                              'Reject suggestion?',
                                                          )
                                                        : t(
                                                              'Delete curated answer?',
                                                          ),
                                                    message: a.is_suggested
                                                        ? t(
                                                              'Reject this suggestion?',
                                                          )
                                                        : t('Delete?'),
                                                    confirmLabel: a.is_suggested
                                                        ? t('Reject')
                                                        : t('Delete'),
                                                    danger: true,
                                                });

                                                if (!ok) {
                                                    return;
                                                }

                                                router.delete(
                                                    `/app/curated-answers/${a.id}`,
                                                );
                                            }}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </SortableList>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
