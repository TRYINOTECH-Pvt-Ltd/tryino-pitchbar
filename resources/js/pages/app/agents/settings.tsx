import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    Clipboard,
    Globe2,
    Info,
    LockKeyhole,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import { AgentForm } from '@/components/agents/agent-form';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import {
    edit as editAgent,
    index as agentsIndex,
    show as showAgent,
    update as updateAgent,
} from '@/routes/agents';
import type { BreadcrumbItem } from '@/types';

type Agent = {
    id: string;
    name: string;
    language_default: string;
    system_prompt: string | null;
    confidence_threshold: number;
    is_published: boolean;
    auto_index_visited_pages: boolean;
    allowed_origins: string[] | null;
    restricted_paths: string[] | null;
};

type Embed = { widget_url: string; snippet: string };

type Props = { agent: Agent; embed: Embed };

function StatusPill({ published }: { published: boolean }) {
    const { t } = useT();

    return (
        <span
            className={
                'inline-flex h-7 items-center gap-2 rounded-md border px-2.5 text-xs font-medium ' +
                (published
                    ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    : 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300')
            }
        >
            <span
                className={
                    'size-1.5 rounded-full ' +
                    (published ? 'bg-emerald-500' : 'bg-amber-500')
                }
            />
            {published ? t('Published') : t('Draft')}
        </span>
    );
}

function SettingMetric({
    label,
    value,
    helper,
}: {
    label: string;
    value: string;
    helper: string;
}) {
    return (
        <div className="border-b px-4 py-4 last:border-b-0 sm:px-5">
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

export default function AgentSettings({ agent, embed }: Props) {
    const { t, tChoice } = useT();
    const [copied, setCopied] = useState(false);
    const [autoIndex, setAutoIndex] = useState(agent.auto_index_visited_pages);
    const confidencePercent = Math.round(agent.confidence_threshold * 100);
    const originsCount = agent.allowed_origins?.length ?? 0;
    const languageLabel = agent.language_default.toUpperCase();

    const originsForm = useForm<{ origins: string }>({
        origins: (agent.allowed_origins ?? []).join('\n'),
    });
    const [originsSaved, setOriginsSaved] = useState(false);

    const restrictedForm = useForm<{ paths: string }>({
        paths: (agent.restricted_paths ?? []).join('\n'),
    });
    const [restrictedSaved, setRestrictedSaved] = useState(false);

    const copySnippet = async () => {
        await navigator.clipboard.writeText(embed.snippet);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    const toggleAutoIndex = (next: boolean) => {
        setAutoIndex(next);
        router.patch(
            updateAgent.url({ agent: agent.id }),
            { auto_index_visited_pages: next },
            { preserveScroll: true, onError: () => setAutoIndex(!next) },
        );
    };

    // useForm types `errors` against the form's data shape (`origins`),
    // but after transform() the server sees the field as `allowed_origins`
    // and reports validation errors under that key — bridge the gap.
    const originsError = (originsForm.errors as Record<string, string>)
        .allowed_origins;

    const saveOrigins = (e: React.FormEvent) => {
        e.preventDefault();
        const list = originsForm.data.origins
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '');

        originsForm.transform(() => ({ allowed_origins: list }));
        originsForm.patch(updateAgent.url({ agent: agent.id }), {
            preserveScroll: true,
            onSuccess: () => {
                setOriginsSaved(true);
                window.setTimeout(() => setOriginsSaved(false), 2000);
            },
        });
    };

    // restricted_paths server error key bridge — same pattern as origins.
    const restrictedError = (restrictedForm.errors as Record<string, string>)
        .restricted_paths;

    const saveRestricted = (e: React.FormEvent) => {
        e.preventDefault();
        const list = restrictedForm.data.paths
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '');

        restrictedForm.transform(() => ({ restricted_paths: list }));
        restrictedForm.patch(updateAgent.url({ agent: agent.id }), {
            preserveScroll: true,
            onSuccess: () => {
                setRestrictedSaved(true);
                window.setTimeout(() => setRestrictedSaved(false), 2000);
            },
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        { title: t('Settings'), href: editAgent({ agent: agent.id }).url },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · settings`} />
            <div className="flex min-h-0 flex-1 flex-col bg-card">
                <div className="flex min-h-10 flex-wrap items-center gap-2 border-b px-3 py-2 sm:py-1.5">
                    <StatusPill published={agent.is_published} />
                    <span className="inline-flex h-7 items-center rounded-md border bg-background px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':lang default language', { lang: languageLabel })}
                    </span>
                    <span className="inline-flex h-7 items-center rounded-md border bg-background px-2.5 text-xs font-normal text-muted-foreground">
                        {t(':percent% confidence', {
                            percent: confidencePercent,
                        })}
                    </span>
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        className="ms-auto"
                    >
                        <Link
                            href={showAgent.url({ agent: agent.id })}
                            prefetch
                        >
                            {t('Agent workspace')}
                            <ArrowUpRight className="size-3.5" />
                        </Link>
                    </Button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <div className="grid gap-4 p-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(340px,0.85fr)]">
                        <div className="grid gap-4">
                            <div>
                                <p className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                                    {t('Settings')}
                                </p>
                                <h1 className="mt-2 text-2xl font-semibold tracking-tight text-foreground">
                                    {t('Agent settings')}
                                </h1>
                                <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                                    {t(
                                        'Tune how this agent answers, where the widget is allowed to run, and what installation code customers should paste onto their site.',
                                    )}
                                </p>
                            </div>

                            <AgentForm
                                mode="edit"
                                agentId={agent.id}
                                initial={{
                                    name: agent.name,
                                    language_default: agent.language_default,
                                    system_prompt: agent.system_prompt ?? '',
                                    confidence_threshold:
                                        agent.confidence_threshold,
                                }}
                            />

                            <Card className="overflow-hidden p-0">
                                <form onSubmit={saveOrigins}>
                                    <div className="border-b px-4 py-4 sm:px-5">
                                        <div className="flex items-start gap-3">
                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground shadow-xs">
                                                <ShieldCheck className="size-4" />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-sm font-semibold text-foreground">
                                                    {t('Allowed origins')}
                                                </p>
                                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                    {t(
                                                        'Restrict this public widget script to trusted domains. Each origin must include the scheme and host.',
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="grid gap-4 px-4 py-4 sm:px-5">
                                        <div className="rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs leading-5 text-amber-800 dark:text-amber-300">
                                            <strong>
                                                {t('Strict match.')}
                                            </strong>{' '}
                                            {t('Subdomains are not inferred.')}{' '}
                                            <code>https://example.com</code>{' '}
                                            {t('does not permit')}{' '}
                                            <code>https://app.example.com</code>
                                            .
                                        </div>

                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor="origins"
                                                className="text-xs text-muted-foreground"
                                            >
                                                {t('One origin per line')}
                                            </Label>
                                            <Textarea
                                                id="origins"
                                                rows={5}
                                                spellCheck={false}
                                                placeholder={
                                                    'https://example.com\nhttps://app.example.com'
                                                }
                                                value={originsForm.data.origins}
                                                aria-invalid={Boolean(
                                                    originsError,
                                                )}
                                                onChange={(e) =>
                                                    originsForm.setData(
                                                        'origins',
                                                        e.target.value,
                                                    )
                                                }
                                                className="font-mono text-xs leading-5"
                                            />
                                            {originsError ? (
                                                <p className="text-xs text-destructive">
                                                    {originsError}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>

                                    <div className="flex flex-col-reverse gap-2 border-t bg-muted/20 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                        <p className="text-xs text-muted-foreground">
                                            {originsCount === 0
                                                ? t(
                                                      'No production origins configured yet.',
                                                  )
                                                : tChoice(
                                                      ':count origin currently configured.|:count origins currently configured.',
                                                      originsCount,
                                                      { count: originsCount },
                                                  )}
                                        </p>
                                        <div className="flex items-center justify-end gap-3">
                                            {originsSaved ? (
                                                <span className="text-xs text-emerald-600 dark:text-emerald-400">
                                                    {t('Saved')}
                                                </span>
                                            ) : null}
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    originsForm.processing
                                                }
                                            >
                                                {t('Save origins')}
                                            </Button>
                                        </div>
                                    </div>
                                </form>
                            </Card>

                            <Card className="overflow-hidden p-0">
                                <form onSubmit={saveRestricted}>
                                    <div className="border-b px-4 py-4 sm:px-5">
                                        <div className="flex items-start gap-3">
                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground shadow-xs">
                                                <ShieldCheck className="size-4" />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-sm font-semibold text-foreground">
                                                    {t('Restricted paths')}
                                                </p>
                                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                    {t(
                                                        'URL paths the widget should NOT mount on. Mirrors',
                                                    )}{' '}
                                                    <em>
                                                        {t('Allowed origins')}
                                                    </em>{' '}
                                                    {t(
                                                        'but for paths within an already-allowed origin — use it to keep the bot off your own /admin, /checkout, or /account flows without touching code.',
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="grid gap-4 px-4 py-4 sm:px-5">
                                        <div className="rounded-lg border bg-muted/30 px-3 py-2 text-xs leading-5 text-muted-foreground">
                                            <p>
                                                <strong>
                                                    {t('Glob patterns.')}
                                                </strong>{' '}
                                                {t('Use')} <code>*</code>{' '}
                                                {t('as a wildcard. Example:')}
                                            </p>
                                            <ul className="mt-1 list-disc ps-5">
                                                <li>
                                                    <code>/admin</code>{' '}
                                                    {t('→ exact path only')}
                                                </li>
                                                <li>
                                                    <code>/admin/*</code>{' '}
                                                    {t(
                                                        '→ anything under /admin',
                                                    )}
                                                </li>
                                                <li>
                                                    <code>/checkout</code>,{' '}
                                                    <code>/account/*</code>{' '}
                                                    {t('— case-insensitive')}
                                                </li>
                                            </ul>
                                        </div>

                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor="restricted-paths"
                                                className="text-xs text-muted-foreground"
                                            >
                                                {t('One path per line')}
                                            </Label>
                                            <Textarea
                                                id="restricted-paths"
                                                rows={5}
                                                spellCheck={false}
                                                placeholder={
                                                    '/admin/*\n/checkout\n/account/*'
                                                }
                                                value={
                                                    restrictedForm.data.paths
                                                }
                                                aria-invalid={Boolean(
                                                    restrictedError,
                                                )}
                                                onChange={(e) =>
                                                    restrictedForm.setData(
                                                        'paths',
                                                        e.target.value,
                                                    )
                                                }
                                                className="font-mono text-xs leading-5"
                                            />
                                            {restrictedError ? (
                                                <p className="text-xs text-destructive">
                                                    {restrictedError}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>

                                    <div className="flex flex-col-reverse gap-2 border-t bg-muted/20 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                        <p className="text-xs text-muted-foreground">
                                            {(agent.restricted_paths?.length ??
                                                0) === 0
                                                ? t(
                                                      'No path restrictions — widget mounts on every allowed origin.',
                                                  )
                                                : tChoice(
                                                      ':count path blocked.|:count paths blocked.',
                                                      agent.restricted_paths
                                                          ?.length ?? 0,
                                                      {
                                                          count:
                                                              agent
                                                                  .restricted_paths
                                                                  ?.length ?? 0,
                                                      },
                                                  )}
                                        </p>
                                        <div className="flex items-center justify-end gap-3">
                                            {restrictedSaved ? (
                                                <span className="text-xs text-emerald-600 dark:text-emerald-400">
                                                    {t('Saved')}
                                                </span>
                                            ) : null}
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    restrictedForm.processing
                                                }
                                            >
                                                {t('Save paths')}
                                            </Button>
                                        </div>
                                    </div>
                                </form>
                            </Card>
                        </div>

                        <aside className="grid content-start gap-4">
                            <Card className="overflow-hidden p-0">
                                <div className="border-b px-4 py-4 sm:px-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-foreground">
                                                {t('Launch snapshot')}
                                            </p>
                                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                {t(
                                                    'The operational state for this agent and its visitor-facing widget.',
                                                )}
                                            </p>
                                        </div>
                                        <Sparkles className="size-4 text-muted-foreground" />
                                    </div>
                                </div>
                                <SettingMetric
                                    label={t('Publishing')}
                                    value={
                                        agent.is_published
                                            ? t('Live')
                                            : t('Draft')
                                    }
                                    helper={
                                        agent.is_published
                                            ? t(
                                                  'Widget installation is available.',
                                              )
                                            : t(
                                                  'Publish from the agent workspace before launch.',
                                              )
                                    }
                                />
                                <SettingMetric
                                    label={t('Allowed origins')}
                                    value={String(originsCount)}
                                    helper={t(
                                        'Exact domains allowed to load this widget.',
                                    )}
                                />
                                <SettingMetric
                                    label={t('Auto-indexing')}
                                    value={autoIndex ? t('On') : t('Off')}
                                    helper={t(
                                        'Visited pages can grow the knowledge base automatically.',
                                    )}
                                />
                            </Card>

                            <Card className="p-4 sm:p-5">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <Globe2 className="size-4 text-muted-foreground" />
                                            <p className="text-sm font-semibold text-foreground">
                                                {t('Auto-index visited pages')}
                                            </p>
                                        </div>
                                        <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                            {t(
                                                'When enabled, new visitor pages are queued for crawl and indexing in the background.',
                                            )}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={autoIndex}
                                        onClick={() =>
                                            toggleAutoIndex(!autoIndex)
                                        }
                                        className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors ${
                                            autoIndex
                                                ? 'bg-emerald-500'
                                                : 'bg-muted'
                                        }`}
                                    >
                                        <span
                                            className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition ${
                                                autoIndex
                                                    ? 'translate-x-5'
                                                    : 'translate-x-1'
                                            }`}
                                        />
                                    </button>
                                </div>
                                <div className="mt-4 rounded-lg border bg-muted/25 px-3 py-2 text-xs leading-5 text-muted-foreground">
                                    {t('Auto-skips')} <code>/admin</code>,{' '}
                                    <code>/checkout</code>, <code>/cart</code>,{' '}
                                    <code>/account</code>, {t('and')}{' '}
                                    <code>/login</code>.{' '}
                                    {t('Capped at 30 pages/hour per agent.')}
                                </div>
                            </Card>

                            <Card
                                className={
                                    'overflow-hidden p-0 ' +
                                    (agent.is_published
                                        ? 'border-emerald-500/30 bg-emerald-500/5'
                                        : 'border-amber-500/25 bg-amber-500/5')
                                }
                            >
                                <div className="border-b px-4 py-4 sm:px-5">
                                    <div className="flex items-start gap-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground shadow-xs">
                                            <LockKeyhole className="size-4" />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-foreground">
                                                {t('Embed on your site')}
                                            </p>
                                            {agent.is_published ? (
                                                <div className="mt-1 grid gap-1.5 text-xs leading-5 text-muted-foreground">
                                                    <p>
                                                        {t(
                                                            'Paste this snippet on every page that should show the agent. Place it just before the closing',
                                                        )}{' '}
                                                        <code>
                                                            &lt;/body&gt;
                                                        </code>{' '}
                                                        {t(
                                                            'tag so the loader runs after the rest of the page paints.',
                                                        )}
                                                    </p>
                                                    <p>
                                                        {t(
                                                            'Alternatively, you can place it inside',
                                                        )}{' '}
                                                        <code>
                                                            &lt;head&gt;
                                                        </code>{' '}
                                                        {t(
                                                            '— the script tag carries `defer`, so it never blocks rendering. Pick whichever spot is easier for your CMS / theme.',
                                                        )}
                                                    </p>
                                                </div>
                                            ) : (
                                                <p className="mt-1 text-xs leading-5 text-amber-700 dark:text-amber-300">
                                                    {t(
                                                        'Publish this agent before the snippet can run for visitors.',
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="grid gap-3 px-4 py-4 sm:px-5">
                                    <pre className="max-h-36 overflow-auto rounded-lg border bg-background/90 p-3 text-xs leading-5 whitespace-pre-wrap text-foreground">
                                        {embed.snippet}
                                    </pre>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <Info className="size-3.5" />
                                            {t('One line, async loader')}
                                        </p>
                                        <Button
                                            onClick={copySnippet}
                                            variant="outline"
                                            size="sm"
                                        >
                                            {copied ? (
                                                <>
                                                    <Check className="size-3" />
                                                    {t('Copied')}
                                                </>
                                            ) : (
                                                <>
                                                    <Clipboard className="size-3" />
                                                    {t('Copy snippet')}
                                                </>
                                            )}
                                        </Button>
                                    </div>
                                </div>
                            </Card>
                        </aside>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
