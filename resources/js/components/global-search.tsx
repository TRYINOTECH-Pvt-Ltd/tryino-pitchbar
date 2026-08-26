import { Link } from '@inertiajs/react';
import {
    Bot,
    Building2,
    Inbox,
    MessagesSquare,
    Search,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import platformSearch from '@/actions/App/Http/Controllers/Admin/Platform/SearchController';
import workspaceSearch from '@/actions/App/Http/Controllers/Admin/SearchController';
import { useT } from '@/lib/i18n';

type Agent = {
    id: string;
    name: string;
    is_published: boolean;
    href: string;
};

type Conversation = {
    id: string;
    agent_name: string | null;
    page_url: string | null;
    started_at: string | null;
    href: string;
};

type Lead = {
    id: string;
    email: string;
    name: string | null;
    status: string;
    href: string;
};

type Results = {
    q: string;
    agents: Agent[];
    conversations: Conversation[];
    leads: Lead[];
};

type PlatformWorkspace = {
    id: string;
    name: string;
    owner: string | null;
    href: string;
};

type PlatformUser = {
    id: string;
    name: string | null;
    email: string;
    role: string;
    href: string;
};

type PlatformAgent = {
    id: string;
    name: string;
    workspace_name: string | null;
    is_published: boolean;
    href: string;
};

type PlatformLead = {
    id: string;
    email: string;
    name: string | null;
    status: string;
    workspace_name: string | null;
    href: string;
};

type PlatformResults = {
    q: string;
    workspaces: PlatformWorkspace[];
    users: PlatformUser[];
    agents: PlatformAgent[];
    leads: PlatformLead[];
};

const WORKSPACE_EMPTY: Results = {
    q: '',
    agents: [],
    conversations: [],
    leads: [],
};

const PLATFORM_EMPTY: PlatformResults = {
    q: '',
    workspaces: [],
    users: [],
    agents: [],
    leads: [],
};

/**
 * Workspace-wide command-palette-style search. Triggered by clicking
 * the header search field, or with Cmd/Ctrl+K from anywhere. Hits
 * a server-side search endpoint with a 200ms debounce so we don't fire
 * on every keystroke.
 */
export function GlobalSearch({
    scope = 'workspace',
}: {
    scope?: 'workspace' | 'platform';
}) {
    const isPlatform = scope === 'platform';
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Results>(WORKSPACE_EMPTY);
    const [platformResults, setPlatformResults] =
        useState<PlatformResults>(PLATFORM_EMPTY);
    const [loading, setLoading] = useState(false);
    const inputRef = useRef<HTMLInputElement | null>(null);

    // Cmd/Ctrl + K to open from anywhere.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((prev) => !prev);

                return;
            }

            if (e.key === 'Escape' && open) {
                setOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    // Auto-focus the input + lock body scroll when open.
    useEffect(() => {
        if (!open) {
            return;
        }

        inputRef.current?.focus();
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = prev;
        };
    }, [open]);

    // Debounced fetch. The clear-results path also lives inside the
    // timeout so all setState() happens off the synchronous effect body
    // (otherwise react-hooks/set-state-in-effect lints fire).
    useEffect(() => {
        if (!open) {
            return;
        }

        const term = q.trim();

        const handle = window.setTimeout(async () => {
            if (term.length < 2) {
                if (isPlatform) {
                    setPlatformResults({ ...PLATFORM_EMPTY, q: term });
                } else {
                    setResults({ ...WORKSPACE_EMPTY, q: term });
                }

                return;
            }

            setLoading(true);

            try {
                const res = await fetch(
                    isPlatform
                        ? platformSearch.url({ query: { q: term } })
                        : workspaceSearch.url({ query: { q: term } }),
                    {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );

                if (res.ok) {
                    const json = await res.json();

                    if (isPlatform) {
                        setPlatformResults(
                            (json.data ?? PLATFORM_EMPTY) as PlatformResults,
                        );
                    } else {
                        setResults((json.data ?? WORKSPACE_EMPTY) as Results);
                    }
                }
            } finally {
                setLoading(false);
            }
        }, 200);

        return () => window.clearTimeout(handle);
    }, [isPlatform, open, q]);

    const total = isPlatform
        ? platformResults.workspaces.length +
          platformResults.users.length +
          platformResults.agents.length +
          platformResults.leads.length
        : results.agents.length +
          results.conversations.length +
          results.leads.length;

    const triggerLabel = isPlatform
        ? t('Search platform')
        : t('Search workspace');
    const dialogPlaceholder = isPlatform
        ? t('Search workspaces, users, agents, leads…')
        : t('Search agents, conversations, leads…');
    const triggerTitle = isPlatform
        ? t('Search workspaces, users, agents, and leads (⌘K)')
        : t('Search across agents, conversations, and leads (⌘K)');

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex h-9 w-full items-center gap-2 rounded-xl border border-border/70 bg-background/80 px-3 text-sm text-muted-foreground shadow-xs transition hover:bg-accent hover:text-foreground sm:w-[250px] lg:w-[300px]"
                title={triggerTitle}
            >
                <Search className="size-4 shrink-0" />
                <span className="min-w-0 flex-1 truncate text-start">
                    {triggerLabel}
                </span>
                <kbd className="hidden rounded border bg-muted px-1.5 py-0.5 font-mono text-[10px] md:inline-flex">
                    ⌘K
                </kbd>
            </button>

            {open && (
                <div
                    className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 px-4 pt-24 backdrop-blur-sm"
                    onClick={() => setOpen(false)}
                >
                    <div
                        className="w-full max-w-2xl overflow-hidden rounded-lg border bg-card shadow-2xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex items-center gap-2 border-b px-4 py-2.5">
                            <Search className="size-4 text-muted-foreground" />
                            <input
                                ref={inputRef}
                                type="search"
                                placeholder={dialogPlaceholder}
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                            />
                            {loading && (
                                <span className="text-[10px] text-muted-foreground">
                                    {t('searching…')}
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                className="rounded text-xs text-muted-foreground hover:text-foreground"
                            >
                                {t('Esc')}
                            </button>
                        </div>

                        <div className="max-h-[60vh] overflow-y-auto">
                            {q.trim().length < 2 ? (
                                <p className="px-4 py-8 text-center text-xs text-muted-foreground">
                                    {t('Type at least 2 characters to search.')}
                                </p>
                            ) : total === 0 ? (
                                <p className="px-4 py-8 text-center text-xs text-muted-foreground">
                                    {loading
                                        ? t('Searching…')
                                        : t('No matches for ":query".', {
                                              query: q,
                                          })}
                                </p>
                            ) : isPlatform ? (
                                <>
                                    {platformResults.workspaces.length > 0 && (
                                        <Section
                                            label={t('Workspaces')}
                                            icon={
                                                <Building2 className="size-3.5 text-sky-600" />
                                            }
                                        >
                                            {platformResults.workspaces.map(
                                                (workspace) => (
                                                    <SearchHit
                                                        key={workspace.id}
                                                        href={workspace.href}
                                                        onSelect={() =>
                                                            setOpen(false)
                                                        }
                                                        title={workspace.name}
                                                        sub={
                                                            workspace.owner ??
                                                            t('workspace')
                                                        }
                                                    />
                                                ),
                                            )}
                                        </Section>
                                    )}

                                    {platformResults.users.length > 0 && (
                                        <Section
                                            label={t('Users')}
                                            icon={
                                                <Users className="size-3.5 text-violet-600" />
                                            }
                                        >
                                            {platformResults.users.map(
                                                (user) => (
                                                    <SearchHit
                                                        key={user.id}
                                                        href={user.href}
                                                        onSelect={() =>
                                                            setOpen(false)
                                                        }
                                                        title={
                                                            user.name ??
                                                            user.email
                                                        }
                                                        sub={`${user.email} · ${user.role}`}
                                                    />
                                                ),
                                            )}
                                        </Section>
                                    )}

                                    {platformResults.agents.length > 0 && (
                                        <Section
                                            label={t('Agents')}
                                            icon={
                                                <Bot className="size-3.5 text-amber-600" />
                                            }
                                        >
                                            {platformResults.agents.map(
                                                (agent) => (
                                                    <SearchHit
                                                        key={agent.id}
                                                        href={agent.href}
                                                        onSelect={() =>
                                                            setOpen(false)
                                                        }
                                                        title={agent.name}
                                                        sub={`${agent.workspace_name ?? t('workspace')} · ${agent.is_published ? t('live') : t('draft')}`}
                                                    />
                                                ),
                                            )}
                                        </Section>
                                    )}

                                    {platformResults.leads.length > 0 && (
                                        <Section
                                            label={t('Leads')}
                                            icon={
                                                <Inbox className="size-3.5 text-emerald-600" />
                                            }
                                        >
                                            {platformResults.leads.map(
                                                (lead) => (
                                                    <SearchHit
                                                        key={lead.id}
                                                        href={lead.href}
                                                        onSelect={() =>
                                                            setOpen(false)
                                                        }
                                                        title={
                                                            lead.name ??
                                                            lead.email
                                                        }
                                                        sub={`${lead.workspace_name ?? lead.email} · ${lead.status}`}
                                                    />
                                                ),
                                            )}
                                        </Section>
                                    )}
                                </>
                            ) : (
                                <>
                                    {results.agents.length > 0 && (
                                        <Section
                                            label={t('Agents')}
                                            icon={
                                                <Bot className="size-3.5 text-amber-600" />
                                            }
                                        >
                                            {results.agents.map((a) => (
                                                <SearchHit
                                                    key={a.id}
                                                    href={a.href}
                                                    onSelect={() =>
                                                        setOpen(false)
                                                    }
                                                    title={a.name}
                                                    sub={
                                                        a.is_published
                                                            ? t('live')
                                                            : t('draft')
                                                    }
                                                />
                                            ))}
                                        </Section>
                                    )}

                                    {results.conversations.length > 0 && (
                                        <Section
                                            label={t('Conversations')}
                                            icon={
                                                <MessagesSquare className="size-3.5 text-rose-600" />
                                            }
                                        >
                                            {results.conversations.map((c) => (
                                                <SearchHit
                                                    key={c.id}
                                                    href={c.href}
                                                    onSelect={() =>
                                                        setOpen(false)
                                                    }
                                                    title={
                                                        c.page_url ??
                                                        t('(no page url)')
                                                    }
                                                    sub={
                                                        c.agent_name
                                                            ? t('via :name', {
                                                                  name: c.agent_name,
                                                              })
                                                            : t('thread')
                                                    }
                                                />
                                            ))}
                                        </Section>
                                    )}

                                    {results.leads.length > 0 && (
                                        <Section
                                            label={t('Leads')}
                                            icon={
                                                <Inbox className="size-3.5 text-emerald-600" />
                                            }
                                        >
                                            {results.leads.map((l) => (
                                                <SearchHit
                                                    key={l.id}
                                                    href={l.href}
                                                    onSelect={() =>
                                                        setOpen(false)
                                                    }
                                                    title={l.name ?? l.email}
                                                    sub={`${l.email} · ${l.status}`}
                                                />
                                            ))}
                                        </Section>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function Section({
    label,
    icon,
    children,
}: {
    label: string;
    icon: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div>
            <div className="flex items-center gap-2 border-t border-b bg-muted/40 px-4 py-1.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase first:border-t-0">
                {icon}
                {label}
            </div>
            <div>{children}</div>
        </div>
    );
}

function SearchHit({
    href,
    title,
    sub,
    onSelect,
}: {
    href: string;
    title: string;
    sub?: string | null;
    onSelect: () => void;
}) {
    return (
        <Link
            href={href}
            onClick={onSelect}
            className="flex items-baseline justify-between gap-3 px-4 py-2 text-sm transition hover:bg-muted"
        >
            <span className="truncate font-medium">{title}</span>
            {sub ? (
                <span className="shrink-0 text-xs text-muted-foreground">
                    {sub}
                </span>
            ) : null}
        </Link>
    );
}
