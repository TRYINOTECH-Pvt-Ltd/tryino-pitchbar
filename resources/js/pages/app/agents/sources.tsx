import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    BookOpen,
    Database,
    Eye,
    FileText,
    Globe,
    Pencil,
    RefreshCw,
    Sheet,
    Trash2,
    Upload,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useAlert, useConfirm } from '@/components/confirm-dialog-provider';
import { PlanLimitBanner } from '@/components/plan-limit-banner';
import { TablePagination } from '@/components/table-pagination';
import type { PaginationMeta } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { cn } from '@/lib/utils';
import { index as agentsIndex, show as showAgent } from '@/routes/agents';
import { index as agentSourcesIndex } from '@/routes/agents/sources';
import { destroy as sourcesDestroy } from '@/routes/sources';
import type { BreadcrumbItem } from '@/types';

type Source = {
    id: string;
    type:
        | 'url'
        | 'sitemap'
        | 'feed'
        | 'notion'
        | 'google_doc'
        | 'google_sheet'
        | 'text'
        | 'auto'
        | 'file'
        | 'sql';
    status: 'pending' | 'crawling' | 'indexed' | 'failed';
    config: {
        url?: string;
        title?: string;
        body?: string;
        source_url?: string;
    } | null;
    last_synced_at: string | null;
    error: string | null;
    created_at: string | null;
    progress: { pages_indexed: number; pages_total: number | null };
    display: { title: string; subtitle: string; link: string | null };
    is_global: boolean;
};

type PreviewChunk = {
    id: string;
    ord: number;
    tokens: number;
    text: string;
    chars: number;
};

type PreviewDoc = {
    id: string;
    url: string | null;
    title: string | null;
    fetched_at: string | null;
    chunks_count: number;
    first_chunk_preview: string;
    chunks: PreviewChunk[];
};

type PreviewPayload = {
    source: { id: string; type: string; status: string; error: string | null };
    progress: { pages_indexed: number; pages_total: number | null };
    documents: PreviewDoc[];
};

type Props = {
    agent: { id: string; name: string };
    sources: Source[];
    pagination: PaginationMeta;
    filters: { q: string };
    integrations?: { google: boolean; notion: boolean };
};

const STATUS_COLOR: Record<string, string> = {
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    crawling: 'bg-sky-500/15 text-sky-700 dark:text-sky-400',
    indexed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    failed: 'bg-rose-500/15 text-rose-700 dark:text-rose-400',
};

type SqlForm = {
    driver: 'mysql' | 'pgsql';
    host: string;
    port: number;
    database: string;
    username: string;
    password: string;
    query: string;
    title: string;
    title_column: string;
    body_column: string;
};

/**
 * Discover URLs from a domain (via robots.txt sitemap + probed paths)
 * and bulk-add the operator's selection. Built on the existing
 * `/sources/discover` + `/sources/bulk` endpoints — onboarding has
 * always used them; this surfaces the same flow on the routine "add
 * sources" page so operators don't have to paste URLs one by one.
 *
 * Adds Select all / Deselect all controls — buyer report 2026-05-26.
 */
function DiscoverUrlsCard({ agent }: { agent: { id: string } }) {
    const { t } = useT();
    const [domain, setDomain] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [discovered, setDiscovered] = useState<{
        root: string;
        sitemap_urls: string[];
        probed_urls: string[];
        total: number;
    } | null>(null);
    const [picked, setPicked] = useState<Record<string, boolean>>({});
    const [submitting, setSubmitting] = useState(false);

    const csrf = (): string =>
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? '';

    const allUrls = (): string[] => {
        if (!discovered) {
            return [];
        }

        return [...discovered.sitemap_urls, ...discovered.probed_urls];
    };

    const pickedCount = Object.values(picked).filter(Boolean).length;
    const totalCount = allUrls().length;

    const selectAll = () => {
        const next: Record<string, boolean> = {};
        allUrls().forEach((u) => (next[u] = true));
        setPicked(next);
    };

    const deselectAll = () => setPicked({});

    const discover = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!domain || loading) {
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const res = await fetch(
                `/app/agents/${agent.id}/sources/discover`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ url: domain, max: 200 }),
                },
            );

            if (!res.ok) {
                const text = await res.text();

                throw new Error(text || `HTTP ${res.status}`);
            }

            const json = (await res.json()) as {
                data: {
                    root: string;
                    sitemap_urls: string[];
                    probed_urls: string[];
                    total: number;
                };
            };
            setDiscovered(json.data);
            // Pre-pick everything by default — operator can untick the
            // ones they don't want before clicking import.
            const initial: Record<string, boolean> = {};
            json.data.sitemap_urls.forEach((u) => (initial[u] = true));
            json.data.probed_urls.forEach((u) => (initial[u] = true));
            setPicked(initial);
        } catch (err) {
            setError(
                err instanceof Error ? err.message : t('Discovery failed.'),
            );
        } finally {
            setLoading(false);
        }
    };

    const importPicked = async () => {
        const urls = Object.entries(picked)
            .filter(([, v]) => v)
            .map(([k]) => k);

        if (urls.length === 0 || submitting) {
            return;
        }

        setSubmitting(true);

        try {
            const formEl = document.createElement('form');
            formEl.method = 'POST';
            formEl.action = `/app/agents/${agent.id}/sources/bulk`;
            const tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = '_token';
            tokenInput.value = csrf();
            formEl.appendChild(tokenInput);
            urls.forEach((u, i) => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = `urls[${i}]`;
                inp.value = u;
                formEl.appendChild(inp);
            });
            document.body.appendChild(formEl);
            formEl.submit();
        } finally {
            // Form submit triggers full-page navigation, so no cleanup
            // strictly required; reset busy in case the user navigated
            // away mid-flight.
            setSubmitting(false);
        }
    };

    return (
        <Card className="mt-3 border-border/80 p-5">
            <h2 className="mb-1 font-medium">
                {t('Discover URLs from a domain')}
            </h2>
            <p className="mb-3 text-xs text-muted-foreground">
                {t(
                    "We crawl robots.txt + sitemap.xml + a handful of common paths and list everything we find. Tick the URLs you want and we'll queue them all for crawling in one go.",
                )}
            </p>
            <form
                onSubmit={discover}
                className="grid gap-3 md:grid-cols-[1fr_auto]"
            >
                <Input
                    type="url"
                    placeholder="https://your-site.com"
                    value={domain}
                    onChange={(e) => setDomain(e.target.value)}
                    disabled={loading}
                />
                <Button type="submit" disabled={loading || !domain.trim()}>
                    {loading ? t('Discovering…') : t('Discover URLs')}
                </Button>
            </form>
            {error && <p className="mt-2 text-xs text-destructive">{error}</p>}

            {discovered && (
                <div className="mt-4 grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-xs text-muted-foreground">
                            {t(':n URL(s) discovered', { n: totalCount })} ·{' '}
                            {t(':n selected', { n: pickedCount })}
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={selectAll}
                                disabled={pickedCount === totalCount}
                            >
                                {t('Select all')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={deselectAll}
                                disabled={pickedCount === 0}
                            >
                                {t('Deselect all')}
                            </Button>
                        </div>
                    </div>
                    <div className="max-h-72 overflow-y-auto rounded-md border">
                        {allUrls().map((u) => (
                            <label
                                key={u}
                                className="flex cursor-pointer items-center gap-2 border-b px-3 py-1.5 text-xs hover:bg-muted/50"
                            >
                                <input
                                    type="checkbox"
                                    checked={picked[u] === true}
                                    onChange={(e) =>
                                        setPicked((p) => ({
                                            ...p,
                                            [u]: e.target.checked,
                                        }))
                                    }
                                    className="size-3.5"
                                />
                                <span className="truncate">{u}</span>
                            </label>
                        ))}
                    </div>
                    <div className="flex justify-end">
                        <Button
                            type="button"
                            onClick={importPicked}
                            disabled={pickedCount === 0 || submitting}
                        >
                            {t('Import :n selected URL(s)', { n: pickedCount })}
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}

function SqlSourceCard({ agent }: { agent: { id: string } }) {
    const { t } = useT();
    const form = useForm<SqlForm>({
        driver: 'mysql',
        host: '',
        port: 3306,
        database: '',
        username: '',
        password: '',
        query: '',
        title: '',
        title_column: '',
        body_column: '',
    });

    return (
        <Card className="border-border/80 p-5">
            <h2 className="mb-1 font-medium">{t('Add SQL database')}</h2>
            <p className="mb-3 text-xs text-muted-foreground">
                {t(
                    'Connect a read-only MySQL or PostgreSQL database. Paste a SELECT query — each row becomes part of the agent knowledge base. Credentials stay encrypted at rest.',
                )}
            </p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/app/agents/${agent.id}/sources/sql`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset(
                                'host',
                                'database',
                                'username',
                                'password',
                                'query',
                                'title',
                                'title_column',
                                'body_column',
                            );
                        },
                    });
                }}
                className="grid gap-3"
            >
                <div
                    role="radiogroup"
                    aria-label={t('Driver')}
                    className="grid gap-2 sm:grid-cols-2"
                >
                    {(
                        [
                            { value: 'mysql', title: 'MySQL', port: 3306 },
                            {
                                value: 'pgsql',
                                title: 'PostgreSQL',
                                port: 5432,
                            },
                        ] as const
                    ).map((opt) => {
                        const checked = form.data.driver === opt.value;

                        return (
                            <label
                                key={opt.value}
                                className={cn(
                                    'group flex cursor-pointer items-center gap-2.5 rounded-md border bg-background p-3 text-sm transition hover:border-primary/40',
                                    checked &&
                                        'border-primary ring-1 ring-primary/30',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="sql-driver"
                                    value={opt.value}
                                    checked={checked}
                                    onChange={() => {
                                        form.setData('driver', opt.value);
                                        form.setData('port', opt.port);
                                    }}
                                    className="size-3.5 accent-primary"
                                />
                                <span className="font-medium">{opt.title}</span>
                            </label>
                        );
                    })}
                </div>

                <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                    <div className="grid gap-1">
                        <Label htmlFor="sql-host">{t('Host')}</Label>
                        <Input
                            id="sql-host"
                            value={form.data.host}
                            onChange={(e) =>
                                form.setData('host', e.target.value)
                            }
                            placeholder="db.example.com"
                            required
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="sql-port">{t('Port')}</Label>
                        <Input
                            id="sql-port"
                            type="number"
                            value={form.data.port}
                            onChange={(e) =>
                                form.setData('port', Number(e.target.value))
                            }
                            required
                        />
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="grid gap-1">
                        <Label htmlFor="sql-database">{t('Database')}</Label>
                        <Input
                            id="sql-database"
                            value={form.data.database}
                            onChange={(e) =>
                                form.setData('database', e.target.value)
                            }
                            required
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="sql-username">{t('Username')}</Label>
                        <Input
                            id="sql-username"
                            value={form.data.username}
                            onChange={(e) =>
                                form.setData('username', e.target.value)
                            }
                            autoComplete="off"
                            required
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="sql-password">{t('Password')}</Label>
                        <Input
                            id="sql-password"
                            type="password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                            autoComplete="new-password"
                            placeholder="••••••••"
                        />
                    </div>
                </div>

                <div className="grid gap-1">
                    <Label htmlFor="sql-query">{t('SELECT query')}</Label>
                    <Textarea
                        id="sql-query"
                        value={form.data.query}
                        onChange={(e) => form.setData('query', e.target.value)}
                        rows={4}
                        spellCheck={false}
                        placeholder="SELECT id, title, body FROM articles WHERE published = 1"
                        className="font-mono text-xs"
                        required
                    />
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Read-only. Must start with SELECT. Max 5,000 rows per sync. Run under a READ ONLY transaction.',
                        )}
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="grid gap-1">
                        <Label htmlFor="sql-title">
                            {t('Source label (optional)')}
                        </Label>
                        <Input
                            id="sql-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder={t('Production CRM tickets')}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="sql-title-column">
                            {t('Title column (optional)')}
                        </Label>
                        <Input
                            id="sql-title-column"
                            value={form.data.title_column}
                            onChange={(e) =>
                                form.setData('title_column', e.target.value)
                            }
                            placeholder="title"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="sql-body-column">
                            {t('Body column (optional)')}
                        </Label>
                        <Input
                            id="sql-body-column"
                            value={form.data.body_column}
                            onChange={(e) =>
                                form.setData('body_column', e.target.value)
                            }
                            placeholder="body"
                        />
                    </div>
                </div>

                {Object.entries(form.errors).map(([field, msg]) => (
                    <p key={field} className="text-xs text-rose-600">
                        {field}: {String(msg)}
                    </p>
                ))}

                <Button type="submit" disabled={form.processing}>
                    {t('Connect & sync')}
                </Button>
            </form>
        </Card>
    );
}

export default function Sources({
    agent,
    sources,
    pagination,
    filters,
    integrations,
}: Props) {
    const { t, tChoice } = useT();
    const confirm = useConfirm();
    const alert = useAlert();
    const form = useForm<{ type: 'url' | 'sitemap'; url: string }>({
        type: 'url',
        url: '',
    });
    const textForm = useForm<{
        title: string;
        body: string;
        source_url: string;
    }>({
        title: '',
        body: '',
        source_url: '',
    });
    // Edit-in-place for pasted-text sources: the body is persisted on the
    // source config at create/update, so the modal prefills from there.
    const editTextForm = useForm<{
        title: string;
        body: string;
        source_url: string;
    }>({
        title: '',
        body: '',
        source_url: '',
    });
    const [editingTextId, setEditingTextId] = useState<string | null>(null);
    const openTextEdit = (source: Source) => {
        editTextForm.setData({
            title: source.config?.title ?? '',
            body: source.config?.body ?? '',
            source_url: source.config?.source_url ?? '',
        });
        editTextForm.clearErrors();
        setEditingTextId(source.id);
    };
    const fileInputRef = useRef<HTMLInputElement>(null);
    // Buyer-reported (2026-05-21): vertical stack of "Add page / Add
    // SQL / Paste / Sheets / Upload" cards was overwhelming. Tabbed
    // layout — one source type visible at a time. URL hash drives
    // initial tab so deep links from docs / onboarding can preselect.
    type TabKey =
        | 'web'
        | 'database'
        | 'paste'
        | 'sheets'
        | 'google_doc'
        | 'notion'
        | 'files';
    const TAB_KEYS: TabKey[] = [
        'web',
        'database',
        'paste',
        'sheets',
        'google_doc',
        'notion',
        'files',
    ];
    const initialSourceTab: TabKey =
        typeof window !== 'undefined' &&
        TAB_KEYS.includes(window.location.hash.slice(1) as TabKey)
            ? (window.location.hash.slice(1) as TabKey)
            : 'web';
    const [activeTab, setActiveTab] = useState<TabKey>(initialSourceTab);
    const switchTab = (key: TabKey) => {
        setActiveTab(key);

        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.hash = key;
            window.history.replaceState(null, '', url.toString());
        }
    };

    const [files, setFiles] = useState<File[]>([]);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [dragOver, setDragOver] = useState(false);
    const [preview, setPreview] = useState<PreviewPayload | null>(null);
    const [previewLoading, setPreviewLoading] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [sheetUrl, setSheetUrl] = useState('');
    const [sheetTabs, setSheetTabs] = useState<
        { title: string; row_count: number; column_count: number }[] | null
    >(null);
    const [sheetTitle, setSheetTitle] = useState('');
    const [chosenTab, setChosenTab] = useState('');
    const [sheetError, setSheetError] = useState<string | null>(null);
    const [sheetLoading, setSheetLoading] = useState(false);
    const [sheetSubmitting, setSheetSubmitting] = useState(false);

    const probeSheet = async () => {
        if (sheetUrl.trim() === '' || sheetLoading) {
            return;
        }

        setSheetLoading(true);
        setSheetError(null);
        setSheetTabs(null);
        setChosenTab('');

        try {
            // Laravel sets `XSRF-TOKEN` as a URL-encoded cookie; the
            // standard pattern is to echo it back via the X-XSRF-TOKEN
            // header so `VerifyCsrfToken` accepts the request.
            const xsrfCookie =
                document.cookie
                    .split(';')
                    .map((c) => c.trim())
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice('XSRF-TOKEN='.length) ?? '';
            const xsrf =
                xsrfCookie === '' ? '' : decodeURIComponent(xsrfCookie);
            const res = await fetch(
                `/app/agents/${agent.id}/sources/google-sheet/metadata`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrf,
                    },
                    body: JSON.stringify({ spreadsheet: sheetUrl }),
                },
            );
            const body = (await res.json()) as
                | {
                      ok: true;
                      title: string;
                      sheets: {
                          title: string;
                          row_count: number;
                          column_count: number;
                      }[];
                  }
                | { ok: false; error: string };

            if (body.ok === true) {
                setSheetTabs(body.sheets);
                setSheetTitle(body.title);
                setChosenTab(body.sheets[0]?.title ?? '');
            } else {
                setSheetError(body.error);
            }
        } catch (err) {
            setSheetError(err instanceof Error ? err.message : 'Probe failed.');
        } finally {
            setSheetLoading(false);
        }
    };

    const submitSheet = (e: React.FormEvent) => {
        e.preventDefault();

        if (chosenTab === '' || sheetUrl.trim() === '' || sheetSubmitting) {
            return;
        }

        setSheetSubmitting(true);
        setSheetError(null);
        router.post(
            `/app/agents/${agent.id}/sources/google-sheet`,
            { spreadsheet: sheetUrl, sheet_title: chosenTab },
            {
                preserveScroll: true,
                onFinish: () => setSheetSubmitting(false),
                onError: (errs) => {
                    const msg =
                        (typeof errs.spreadsheet === 'string'
                            ? errs.spreadsheet
                            : typeof errs.sheet_title === 'string'
                              ? errs.sheet_title
                              : null) ?? t('Could not add this Google Sheet.');
                    setSheetError(msg);
                },
                onSuccess: () => {
                    setSheetUrl('');
                    setSheetTabs(null);
                    setSheetTitle('');
                    setChosenTab('');
                    setSheetError(null);
                },
            },
        );
    };

    const [googleDocUrl, setGoogleDocUrl] = useState('');
    const [googleDocSubmitting, setGoogleDocSubmitting] = useState(false);
    const [googleDocError, setGoogleDocError] = useState<string | null>(null);

    const submitGoogleDoc = (e: React.FormEvent) => {
        e.preventDefault();

        if (googleDocUrl.trim() === '' || googleDocSubmitting) {
            return;
        }

        setGoogleDocSubmitting(true);
        setGoogleDocError(null);
        router.post(
            `/app/agents/${agent.id}/sources/google-doc`,
            { doc: googleDocUrl },
            {
                preserveScroll: true,
                onFinish: () => setGoogleDocSubmitting(false),
                onError: (errs) => {
                    setGoogleDocError(
                        typeof errs.doc === 'string'
                            ? errs.doc
                            : t('Could not add this Google Doc.'),
                    );
                },
                onSuccess: () => {
                    setGoogleDocUrl('');
                },
            },
        );
    };

    const [notionUrl, setNotionUrl] = useState('');
    const [notionSubmitting, setNotionSubmitting] = useState(false);
    const [notionError, setNotionError] = useState<string | null>(null);

    const submitNotion = (e: React.FormEvent) => {
        e.preventDefault();

        if (notionUrl.trim() === '' || notionSubmitting) {
            return;
        }

        setNotionSubmitting(true);
        setNotionError(null);
        router.post(
            `/app/agents/${agent.id}/sources/notion`,
            { page: notionUrl },
            {
                preserveScroll: true,
                onFinish: () => setNotionSubmitting(false),
                onError: (errs) => {
                    setNotionError(
                        typeof errs.page === 'string'
                            ? errs.page
                            : t('Could not add this Notion page.'),
                    );
                },
                onSuccess: () => {
                    setNotionUrl('');
                },
            },
        );
    };

    const refreshSources = () => {
        setRefreshing(true);
        // router.reload doesn't navigate, so scroll is preserved
        // implicitly — no preserveScroll option needed (and Inertia v3
        // ReloadOptions doesn't expose it anyway).
        router.reload({
            only: ['sources'],
            onFinish: () => setRefreshing(false),
        });
    };

    const openPreview = async (sourceId: string) => {
        setPreviewLoading(sourceId);

        try {
            const response = await fetch(`/app/sources/${sourceId}/preview`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const json = await response.json();
            setPreview(json.data as PreviewPayload);
        } catch (e) {
            await alert({
                message: e instanceof Error ? e.message : t('Preview failed.'),
            });
        } finally {
            setPreviewLoading(null);
        }
    };

    // Esc to close + body scroll-lock while the preview modal is open.
    useEffect(() => {
        if (preview === null) {
            return;
        }

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setPreview(null);
            }
        };
        document.addEventListener('keydown', onKey);

        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = prevOverflow;
        };
    }, [preview]);

    const submitFiles = async () => {
        if (files.length === 0) {
            return;
        }

        setUploading(true);
        setUploadError(null);

        const fd = new FormData();

        for (const f of files) {
            fd.append('files[]', f);
        }

        fd.append(
            '_token',
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement | null
            )?.content ?? '',
        );

        try {
            const response = await fetch(`/app/agents/${agent.id}/uploads`, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement | null
                        )?.content ?? '',
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                const text = await response.text();

                throw new Error(text || `HTTP ${response.status}`);
            }

            setFiles([]);

            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }

            router.reload({ only: ['sources'] });
        } catch (e) {
            setUploadError(
                e instanceof Error ? e.message : t('Upload failed.'),
            );
        } finally {
            setUploading(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Agents'), href: agentsIndex.url() },
        { title: agent.name, href: showAgent({ agent: agent.id }).url },
        {
            title: t('Sources'),
            href: agentSourcesIndex({ agent: agent.id }).url,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${agent.name} · sources`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Knowledge sources')}
                </h1>

                <PlanLimitBanner resource="source" />

                <div
                    className="flex gap-1 overflow-x-auto border-b"
                    role="tablist"
                    aria-label={t('Source types')}
                >
                    {(
                        [
                            { key: 'web', label: t('Web page'), icon: Globe },
                            {
                                key: 'database',
                                label: t('SQL database'),
                                icon: Database,
                            },
                            {
                                key: 'paste',
                                label: t('Paste text'),
                                icon: FileText,
                            },
                            {
                                key: 'sheets',
                                label: t('Google Sheets'),
                                icon: Sheet,
                            },
                            {
                                key: 'google_doc',
                                label: t('Google Docs'),
                                icon: FileText,
                            },
                            {
                                key: 'notion',
                                label: t('Notion'),
                                icon: BookOpen,
                            },
                            {
                                key: 'files',
                                label: t('Upload files'),
                                icon: Upload,
                            },
                        ] as const
                    ).map((tab) => {
                        const Icon = tab.icon;
                        const isActive = activeTab === tab.key;

                        return (
                            <button
                                key={tab.key}
                                type="button"
                                role="tab"
                                aria-selected={isActive}
                                aria-controls={`source-tab-${tab.key}`}
                                onClick={() => switchTab(tab.key)}
                                className={`-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm whitespace-nowrap transition ${
                                    isActive
                                        ? 'border-foreground font-medium text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                <Icon className="size-4" />
                                {tab.label}
                            </button>
                        );
                    })}
                </div>

                {activeTab === 'web' && (
                    <Card className="border-border/80 p-5">
                        <h2 className="mb-1 font-medium">{t('Add a page')}</h2>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {t(
                                "Paste any page from your site. We'll fetch it, extract the text, and index it for the agent.",
                            )}
                        </p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(`/app/agents/${agent.id}/sources`, {
                                    onSuccess: () => form.reset('url'),
                                });
                            }}
                            className="grid gap-3 md:grid-cols-[1fr_auto]"
                        >
                            <div className="grid gap-1.5">
                                <Input
                                    id="url"
                                    type="url"
                                    placeholder="https://your-site.com/about"
                                    value={form.data.url}
                                    onChange={(e) =>
                                        form.setData('url', e.target.value)
                                    }
                                />
                                {form.errors.url && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {form.errors.url}
                                    </p>
                                )}
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                {form.data.type === 'sitemap'
                                    ? t('Add sitemap & crawl all')
                                    : t('Add & crawl')}
                            </Button>
                        </form>
                        <div className="mt-3 grid gap-2 rounded-md border bg-muted/20 p-3">
                            <Label className="text-xs">
                                {t('Source type')}
                            </Label>
                            <div
                                role="radiogroup"
                                aria-label={t('Source type')}
                                className="grid gap-2 sm:grid-cols-2"
                            >
                                {(
                                    [
                                        {
                                            value: 'url',
                                            title: t('Single URL'),
                                            desc: t(
                                                'Crawl just this one page.',
                                            ),
                                        },
                                        {
                                            value: 'sitemap',
                                            title: t('Sitemap'),
                                            desc: t(
                                                'Crawl all URLs in the sitemap.xml.',
                                            ),
                                        },
                                    ] as const
                                ).map((opt) => {
                                    const checked =
                                        form.data.type === opt.value;

                                    return (
                                        <label
                                            key={opt.value}
                                            className={cn(
                                                'group relative flex cursor-pointer items-start gap-2.5 rounded-md border bg-background p-3 text-sm transition hover:border-primary/40',
                                                checked &&
                                                    'border-primary ring-1 ring-primary/30',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="source-type"
                                                value={opt.value}
                                                checked={checked}
                                                onChange={() =>
                                                    form.setData(
                                                        'type',
                                                        opt.value,
                                                    )
                                                }
                                                className="mt-1 size-3.5 accent-primary"
                                            />
                                            <div className="min-w-0">
                                                <div className="font-medium">
                                                    {opt.title}
                                                </div>
                                                <div className="mt-0.5 text-xs text-muted-foreground">
                                                    {opt.desc}
                                                </div>
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Only switch to "Sitemap" if you\'re pasting a real',
                                )}{' '}
                                <code>sitemap.xml</code>.
                            </p>
                        </div>
                    </Card>
                )}

                {activeTab === 'web' && <DiscoverUrlsCard agent={agent} />}

                {activeTab === 'database' && <SqlSourceCard agent={agent} />}

                {activeTab === 'paste' && (
                    <Card className="border-border/80 p-5">
                        <h2 className="mb-1 font-medium">
                            {t('Paste content directly')}
                        </h2>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {t(
                                "When a URL won't crawl (anti-bot challenges, login walls, JS-only sites), paste the page content here. We skip fetching and index it as-is.",
                            )}
                        </p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                textForm.post(
                                    `/app/agents/${agent.id}/sources/text`,
                                    {
                                        onSuccess: () => {
                                            textForm.reset(
                                                'title',
                                                'body',
                                                'source_url',
                                            );
                                        },
                                    },
                                );
                            }}
                            className="grid gap-3"
                        >
                            <div className="grid gap-3 md:grid-cols-[1fr_1fr]">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="text-title">
                                        {t('Title (optional)')}
                                    </Label>
                                    <Input
                                        id="text-title"
                                        type="text"
                                        placeholder={t(
                                            'MacBook Air M5 — pricing & specs',
                                        )}
                                        value={textForm.data.title}
                                        onChange={(e) =>
                                            textForm.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="text-source-url">
                                        {t('Source URL (optional)')}
                                    </Label>
                                    <Input
                                        id="text-source-url"
                                        type="url"
                                        placeholder="https://..."
                                        value={textForm.data.source_url}
                                        onChange={(e) =>
                                            textForm.setData(
                                                'source_url',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="text-body">
                                    {t('Content')}
                                </Label>
                                <Textarea
                                    id="text-body"
                                    rows={6}
                                    placeholder={t(
                                        'Paste the text content here…',
                                    )}
                                    className="min-h-36 resize-y"
                                    value={textForm.data.body}
                                    onChange={(e) =>
                                        textForm.setData('body', e.target.value)
                                    }
                                />
                                {textForm.errors.body && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {textForm.errors.body}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t(':count chars · need at least 50', {
                                        count: textForm.data.body.length.toLocaleString(),
                                    })}
                                </p>
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    disabled={
                                        textForm.processing ||
                                        textForm.data.body.length < 50
                                    }
                                >
                                    {textForm.processing
                                        ? t('Indexing…')
                                        : t('Add as source')}
                                </Button>
                            </div>
                        </form>
                    </Card>
                )}

                {activeTab === 'sheets' && (
                    <Card className="border-border/80 p-5">
                        <div className="mb-1 flex items-center gap-2 font-medium">
                            <Sheet className="size-4 text-emerald-700" />
                            <h2>{t('Google Sheets')}</h2>
                        </div>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {t(
                                'Paste a Google Sheets URL or workbook id. The first row becomes the column headers; every subsequent row is indexed as a chunk and refreshed hourly.',
                            )}
                        </p>
                        <form onSubmit={submitSheet} className="grid gap-3">
                            <div className="grid gap-3 md:grid-cols-[1fr_auto]">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="sheet-url">
                                        {t('Spreadsheet URL or id')}
                                    </Label>
                                    <Input
                                        id="sheet-url"
                                        type="text"
                                        placeholder="https://docs.google.com/spreadsheets/d/..."
                                        value={sheetUrl}
                                        onChange={(e) =>
                                            setSheetUrl(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="grid items-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={probeSheet}
                                        disabled={
                                            sheetLoading ||
                                            sheetUrl.trim() === ''
                                        }
                                    >
                                        {sheetLoading
                                            ? t('Fetching tabs…')
                                            : t('Load sheets')}
                                    </Button>
                                </div>
                            </div>
                            {sheetError && (
                                <p className="text-xs text-destructive">
                                    {sheetError}
                                </p>
                            )}
                            {sheetTabs !== null && sheetTabs.length > 0 && (
                                <div className="grid gap-1.5 rounded-md border bg-muted/20 p-3">
                                    <p className="text-xs text-muted-foreground">
                                        {t('Workbook:')}{' '}
                                        <span className="font-medium text-foreground">
                                            {sheetTitle}
                                        </span>
                                    </p>
                                    <Label
                                        htmlFor="sheet-tab"
                                        className="text-xs"
                                    >
                                        {t('Sheet (tab)')}
                                    </Label>
                                    <Select
                                        value={chosenTab}
                                        onValueChange={setChosenTab}
                                    >
                                        <SelectTrigger id="sheet-tab">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sheetTabs.map((s) => (
                                                <SelectItem
                                                    key={s.title}
                                                    value={s.title}
                                                >
                                                    {s.title} ({s.row_count}{' '}
                                                    rows × {s.column_count}{' '}
                                                    cols)
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <div className="flex justify-end pt-2">
                                        <Button
                                            type="submit"
                                            disabled={
                                                chosenTab === '' ||
                                                sheetSubmitting
                                            }
                                        >
                                            {sheetSubmitting
                                                ? t('Indexing…')
                                                : t('Add this sheet')}
                                        </Button>
                                    </div>
                                </div>
                            )}
                            {sheetTabs !== null && sheetTabs.length === 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {t('This workbook has no readable sheets.')}
                                </p>
                            )}
                        </form>
                    </Card>
                )}

                {activeTab === 'google_doc' && (
                    <Card className="border-border/80 p-5">
                        <div className="mb-1 flex items-center gap-2 font-medium">
                            <FileText className="size-4 text-blue-600" />
                            <h2>{t('Google Docs')}</h2>
                        </div>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {t(
                                'Paste a Google Doc URL or file id. The document is ingested via the workspace Google Drive connection.',
                            )}
                        </p>
                        {integrations && !integrations.google && (
                            <div className="mb-3 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs">
                                {t(
                                    'Google Drive is not connected for this workspace yet.',
                                )}{' '}
                                <Link
                                    href="/app/integrations"
                                    className="font-medium underline"
                                >
                                    {t('Connect it in Integrations')}
                                </Link>{' '}
                                {t('first.')}
                            </div>
                        )}
                        <form
                            onSubmit={submitGoogleDoc}
                            className="grid gap-3 md:grid-cols-[1fr_auto]"
                        >
                            <div className="grid gap-1.5">
                                <Label htmlFor="google-doc-url">
                                    {t('Google Doc URL or id')}
                                </Label>
                                <Input
                                    id="google-doc-url"
                                    type="text"
                                    placeholder="https://docs.google.com/document/d/..."
                                    value={googleDocUrl}
                                    onChange={(e) =>
                                        setGoogleDocUrl(e.target.value)
                                    }
                                    disabled={
                                        integrations !== undefined &&
                                        !integrations.google
                                    }
                                />
                                {googleDocError && (
                                    <p className="text-xs text-destructive">
                                        {googleDocError}
                                    </p>
                                )}
                            </div>
                            <div className="grid items-end">
                                <Button
                                    type="submit"
                                    disabled={
                                        googleDocSubmitting ||
                                        googleDocUrl.trim() === '' ||
                                        (integrations !== undefined &&
                                            !integrations.google)
                                    }
                                >
                                    {googleDocSubmitting
                                        ? t('Queuing…')
                                        : t('Add Google Doc')}
                                </Button>
                            </div>
                        </form>
                    </Card>
                )}

                {activeTab === 'notion' && (
                    <Card className="border-border/80 p-5">
                        <div className="mb-1 flex items-center gap-2 font-medium">
                            <BookOpen className="size-4 text-zinc-700 dark:text-zinc-300" />
                            <h2>{t('Notion')}</h2>
                        </div>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {t(
                                'Paste a Notion page URL or page id. The page is ingested via the workspace Notion connection.',
                            )}
                        </p>
                        {integrations && !integrations.notion && (
                            <div className="mb-3 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs">
                                {t(
                                    'Notion is not connected for this workspace yet.',
                                )}{' '}
                                <Link
                                    href="/app/integrations"
                                    className="font-medium underline"
                                >
                                    {t('Connect it in Integrations')}
                                </Link>{' '}
                                {t('first.')}
                            </div>
                        )}
                        <form
                            onSubmit={submitNotion}
                            className="grid gap-3 md:grid-cols-[1fr_auto]"
                        >
                            <div className="grid gap-1.5">
                                <Label htmlFor="notion-url">
                                    {t('Notion page URL or id')}
                                </Label>
                                <Input
                                    id="notion-url"
                                    type="text"
                                    placeholder="https://www.notion.so/Workspace/Page-..."
                                    value={notionUrl}
                                    onChange={(e) =>
                                        setNotionUrl(e.target.value)
                                    }
                                    disabled={
                                        integrations !== undefined &&
                                        !integrations.notion
                                    }
                                />
                                {notionError && (
                                    <p className="text-xs text-destructive">
                                        {notionError}
                                    </p>
                                )}
                            </div>
                            <div className="grid items-end">
                                <Button
                                    type="submit"
                                    disabled={
                                        notionSubmitting ||
                                        notionUrl.trim() === '' ||
                                        (integrations !== undefined &&
                                            !integrations.notion)
                                    }
                                >
                                    {notionSubmitting
                                        ? t('Queuing…')
                                        : t('Add Notion page')}
                                </Button>
                            </div>
                        </form>
                    </Card>
                )}

                {activeTab === 'files' && (
                    <Card className="border-border/80 p-5">
                        <h2 className="mb-3 font-medium">
                            {t('Upload files')}
                        </h2>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {t(
                                'PDF, DOCX, XLSX, CSV, TXT, MD. Max 10 files, 50\u00a0MB each. Spreadsheet parsing requires Cloudflare Workers AI.',
                            )}
                        </p>
                        <div
                            onDragOver={(e) => {
                                e.preventDefault();
                                setDragOver(true);
                            }}
                            onDragLeave={() => setDragOver(false)}
                            onDrop={(e) => {
                                e.preventDefault();
                                setDragOver(false);
                                const dropped = Array.from(
                                    e.dataTransfer?.files ?? [],
                                ).slice(0, 10);
                                setFiles((prev) =>
                                    [...prev, ...dropped].slice(0, 10),
                                );
                            }}
                            onClick={() => fileInputRef.current?.click()}
                            className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-8 text-center text-sm transition ${
                                dragOver
                                    ? 'border-foreground/50 bg-muted/70'
                                    : 'border-muted-foreground/25 bg-muted/20 hover:bg-muted/35'
                            }`}
                        >
                            <Upload className="size-5 text-muted-foreground" />
                            <p className="text-muted-foreground">
                                {files.length > 0
                                    ? tChoice(
                                          ':count file selected — drop more or click|:count files selected — drop more or click',
                                          files.length,
                                          { count: files.length },
                                      )
                                    : t('Drop files here or click to browse')}
                            </p>
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                accept=".pdf,.docx,.doc,.xlsx,.xls,.ods,.odt,.csv,.txt,.md,.markdown"
                                className="hidden"
                                onChange={(e) => {
                                    const picked = Array.from(
                                        e.currentTarget.files ?? [],
                                    ).slice(0, 10);
                                    setFiles((prev) =>
                                        [...prev, ...picked].slice(0, 10),
                                    );
                                }}
                            />
                        </div>

                        {files.length > 0 && (
                            <ul className="mt-3 divide-y rounded-md border text-sm">
                                {files.map((f, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center justify-between px-3 py-2"
                                    >
                                        <span className="truncate">
                                            {f.name}
                                        </span>
                                        <span className="ms-2 shrink-0 text-xs text-muted-foreground">
                                            {(f.size / 1024).toFixed(1)} KB
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {uploadError && (
                            <p className="mt-2 text-xs text-destructive">
                                {uploadError}
                            </p>
                        )}

                        <div className="mt-3 flex gap-2">
                            <Button
                                type="button"
                                disabled={files.length === 0 || uploading}
                                onClick={submitFiles}
                            >
                                {uploading
                                    ? t('Uploading…')
                                    : tChoice(
                                          'Upload :count file|Upload :count files',
                                          files.length,
                                          { count: files.length },
                                      )}
                            </Button>
                            {files.length > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setFiles([])}
                                    disabled={uploading}
                                >
                                    {t('Clear')}
                                </Button>
                            )}
                        </div>
                    </Card>
                )}

                <Card className="p-4">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <div className="flex items-center gap-3">
                            <h2 className="font-medium">
                                {t('Existing sources (:total)', {
                                    total: pagination.total,
                                })}
                            </h2>
                            <TableSearch
                                placeholder={t('Search sources…')}
                                initialValue={filters.q}
                                only={['sources', 'pagination', 'filters']}
                            />
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={refreshSources}
                            disabled={refreshing}
                            title={t(
                                'Re-fetch the latest status (after a crawl finishes)',
                            )}
                        >
                            <RefreshCw
                                className={`size-4 ${refreshing ? 'animate-spin' : ''}`}
                            />
                            {refreshing ? t('Refreshing…') : t('Refresh')}
                        </Button>
                    </div>
                    {sources.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('None yet — add a URL above.')}
                        </p>
                    ) : (
                        <div className="divide-y">
                            {sources.map((source) => (
                                <div
                                    key={source.id}
                                    className="flex items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        {source.display.link ? (
                                            <a
                                                href={source.display.link}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="block truncate text-sm font-medium hover:underline"
                                            >
                                                {source.display.title}
                                            </a>
                                        ) : (
                                            <p className="truncate text-sm font-medium">
                                                {source.display.title}
                                            </p>
                                        )}
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {source.is_global && (
                                                <span className="mr-1.5 inline-flex items-center gap-1 rounded bg-primary/10 px-1.5 py-0.5 font-medium text-primary">
                                                    <Globe className="size-3" />
                                                    {t('Every page')}
                                                </span>
                                            )}
                                            {source.display.subtitle} ·{' '}
                                            {source.last_synced_at
                                                ? t('synced :date', {
                                                      date: new Date(
                                                          source.last_synced_at,
                                                      ).toLocaleString(),
                                                  })
                                                : t('never synced')}
                                            {source.progress.pages_indexed >
                                                0 && (
                                                <>
                                                    {' '}
                                                    ·{' '}
                                                    {tChoice(
                                                        ':indexed page|:indexed pages',
                                                        source.progress
                                                            .pages_indexed,
                                                        {
                                                            indexed: source
                                                                .progress
                                                                .pages_total
                                                                ? `${source.progress.pages_indexed} / ${source.progress.pages_total}`
                                                                : source
                                                                      .progress
                                                                      .pages_indexed,
                                                        },
                                                    )}
                                                </>
                                            )}
                                            {source.error && (
                                                <>
                                                    {' '}
                                                    ·{' '}
                                                    <span className="text-destructive">
                                                        {source.error}
                                                    </span>
                                                </>
                                            )}
                                        </p>
                                    </div>
                                    <span
                                        className={`rounded px-2 py-0.5 text-xs ${STATUS_COLOR[source.status] ?? ''}`}
                                    >
                                        {t(source.status)}
                                    </span>
                                    <Button
                                        variant={
                                            source.is_global
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        aria-pressed={source.is_global}
                                        aria-label={t('Answer on every page')}
                                        onClick={() =>
                                            router.patch(
                                                `/app/sources/${source.id}/global`,
                                                {
                                                    is_global:
                                                        !source.is_global,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                        title={
                                            source.is_global
                                                ? t(
                                                      'Answers on every page — contact, address and opening-hours facts stay reachable from any page. Click to turn off.',
                                                  )
                                                : t(
                                                      'Answer on every page — best for contact details, address and opening hours, so visitors get them no matter which page they are on.',
                                                  )
                                        }
                                    >
                                        <Globe className="size-4" />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => openPreview(source.id)}
                                        disabled={previewLoading === source.id}
                                        title={t('Preview what was extracted')}
                                    >
                                        <Eye className="size-4" />
                                    </Button>
                                    {source.type === 'text' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => openTextEdit(source)}
                                            title={t('Edit pasted content')}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                    )}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/app/sources/${source.id}/reindex`,
                                            )
                                        }
                                    >
                                        {t('Reindex')}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={async () => {
                                            const ok = await confirm({
                                                title: t('Delete source?'),
                                                message: t(
                                                    'Delete this source?',
                                                ),
                                                confirmLabel: t('Delete'),
                                                danger: true,
                                            });

                                            if (!ok) {
                                                return;
                                            }

                                            router.delete(
                                                sourcesDestroy.url({
                                                    source: source.id,
                                                }),
                                                {
                                                    preserveScroll: true,
                                                    onError: (errors) => {
                                                        const message =
                                                            Object.values(
                                                                errors,
                                                            )[0] ??
                                                            t(
                                                                'Could not delete source. Try again.',
                                                            );
                                                        void alert({
                                                            title: t(
                                                                'Delete failed',
                                                            ),
                                                            message:
                                                                String(message),
                                                            confirmLabel:
                                                                t('OK'),
                                                        });
                                                    },
                                                    onSuccess: () => {
                                                        router.reload({
                                                            only: [
                                                                'sources',
                                                                'pagination',
                                                                'filters',
                                                            ],
                                                        });
                                                    },
                                                },
                                            );
                                        }}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                    <TablePagination
                        pagination={pagination}
                        only={['sources', 'pagination', 'filters']}
                    />
                </Card>

                {preview !== null && (
                    <div
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                        onClick={() => setPreview(null)}
                    >
                        <div
                            className="max-h-[80vh] w-full max-w-3xl overflow-y-auto rounded-lg border bg-background p-6 shadow-lg"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="mb-4 flex items-center justify-between">
                                <h3 className="text-lg font-semibold">
                                    {t('What the AI sees for this source')}
                                </h3>
                                <button
                                    type="button"
                                    onClick={() => setPreview(null)}
                                    className="text-sm text-muted-foreground hover:text-foreground"
                                >
                                    {t('Close')}
                                </button>
                            </div>
                            <p className="mb-4 text-xs text-muted-foreground">
                                {t('Status')}{' '}
                                <span className="font-medium">
                                    {t(preview.source.status)}
                                </span>{' '}
                                ·{' '}
                                {tChoice(
                                    ':indexed page indexed|:indexed pages indexed',
                                    preview.progress.pages_indexed,
                                    {
                                        indexed: preview.progress.pages_total
                                            ? `${preview.progress.pages_indexed} / ${preview.progress.pages_total}`
                                            : preview.progress.pages_indexed,
                                    },
                                )}
                                {preview.source.error && (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <span className="text-destructive">
                                            {preview.source.error}
                                        </span>
                                    </>
                                )}
                            </p>
                            {preview.documents.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'No content extracted yet. Try Reindex, or check the error message above.',
                                    )}
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {preview.documents.map((doc) => (
                                        <div
                                            key={doc.id}
                                            className="rounded border p-3"
                                        >
                                            <p className="truncate text-sm font-medium">
                                                {doc.title ?? t('(no title)')}
                                            </p>
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                {doc.url ?? ''}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {tChoice(
                                                    ':count chunk|:count chunks',
                                                    doc.chunks_count,
                                                    { count: doc.chunks_count },
                                                )}
                                                {doc.chunks.length <
                                                    doc.chunks_count && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {t(
                                                            'showing first :count',
                                                            {
                                                                count: doc
                                                                    .chunks
                                                                    .length,
                                                            },
                                                        )}
                                                    </>
                                                )}
                                                {doc.fetched_at && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {t('fetched :date', {
                                                            date: new Date(
                                                                doc.fetched_at,
                                                            ).toLocaleString(),
                                                        })}
                                                    </>
                                                )}
                                            </p>

                                            {doc.chunks.length === 0 ? (
                                                <pre className="mt-2 rounded bg-muted p-2 text-xs">
                                                    {t('(empty)')}
                                                </pre>
                                            ) : (
                                                <details className="mt-2" open>
                                                    <summary className="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
                                                        {t('View chunks')}
                                                    </summary>
                                                    <div className="mt-2 max-h-72 space-y-2 overflow-y-auto">
                                                        {doc.chunks.map((c) => (
                                                            <div
                                                                key={c.id}
                                                                className="rounded border bg-muted/40 p-2 text-xs"
                                                            >
                                                                <p className="mb-1 text-[10px] tracking-wide text-muted-foreground uppercase">
                                                                    {t(
                                                                        'chunk :ord · :tokens tokens',
                                                                        {
                                                                            ord: c.ord,
                                                                            tokens: c.tokens,
                                                                        },
                                                                    )}
                                                                </p>
                                                                <pre className="leading-snug whitespace-pre-wrap">
                                                                    {c.text}
                                                                </pre>
                                                                {c.chars >
                                                                    c.text
                                                                        .length && (
                                                                    <p className="mt-1 text-[10px] text-muted-foreground/60 italic">
                                                                        {t(
                                                                            '+:count more characters (trimmed for display only — all of it is indexed)',
                                                                            {
                                                                                count:
                                                                                    c.chars -
                                                                                    c
                                                                                        .text
                                                                                        .length,
                                                                            },
                                                                        )}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </details>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {editingTextId !== null && (
                    <div
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                        onClick={() => setEditingTextId(null)}
                    >
                        <form
                            className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg border bg-background p-6 shadow-lg"
                            onClick={(e) => e.stopPropagation()}
                            onSubmit={(e) => {
                                e.preventDefault();
                                editTextForm.patch(
                                    `/app/sources/${editingTextId}/text`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setEditingTextId(null),
                                    },
                                );
                            }}
                        >
                            <div className="mb-4 flex items-center justify-between">
                                <h3 className="text-lg font-semibold">
                                    {t('Edit pasted content')}
                                </h3>
                                <button
                                    type="button"
                                    onClick={() => setEditingTextId(null)}
                                    className="text-sm text-muted-foreground hover:text-foreground"
                                >
                                    {t('Close')}
                                </button>
                            </div>
                            <div className="grid gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="edit-text-title">
                                        {t('Title (optional)')}
                                    </Label>
                                    <Input
                                        id="edit-text-title"
                                        type="text"
                                        value={editTextForm.data.title}
                                        onChange={(e) =>
                                            editTextForm.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="edit-text-source-url">
                                        {t('Source URL (optional)')}
                                    </Label>
                                    <Input
                                        id="edit-text-source-url"
                                        type="url"
                                        placeholder="https://..."
                                        value={editTextForm.data.source_url}
                                        onChange={(e) =>
                                            editTextForm.setData(
                                                'source_url',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="edit-text-body">
                                        {t('Content')}
                                    </Label>
                                    <Textarea
                                        id="edit-text-body"
                                        rows={10}
                                        className="min-h-48 resize-y"
                                        value={editTextForm.data.body}
                                        onChange={(e) =>
                                            editTextForm.setData(
                                                'body',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {editTextForm.errors.body && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {editTextForm.errors.body}
                                        </p>
                                    )}
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t(':count chars · need at least 50', {
                                            count: editTextForm.data.body.length.toLocaleString(),
                                        })}
                                    </p>
                                </div>
                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setEditingTextId(null)}
                                    >
                                        {t('Cancel')}
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={editTextForm.processing}
                                    >
                                        {t('Save & re-index')}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
