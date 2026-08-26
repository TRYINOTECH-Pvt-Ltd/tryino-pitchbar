import { Cloud, Gauge, Loader2, Sparkles, Wrench } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ModelCatalogEntry = {
    id: string;
    label: string;
    provider: 'cloudflare' | 'openai' | 'openrouter';
    ttft_ms: number;
    tier: 'fast' | 'medium' | 'slow';
    cost: string;
    context_tokens: number;
    supports_tools: boolean;
    recommended: boolean;
    notes: string;
};

export type ModelLatency = {
    ok: boolean;
    ttft_ms: number | null;
    total_ms: number | null;
    measured_at: string | null;
    error: string | null;
    provider: string;
    model: string;
};

type Props = {
    id: string;
    label: string;
    provider: 'cloudflare' | 'openai' | 'openrouter';
    value: string;
    onChange: (v: string) => void;
    catalog: ModelCatalogEntry[];
    initialLatency?: ModelLatency | null;
    placeholder?: string;
    helpText?: string;
};

const CUSTOM_VALUE = '__custom__';

const tierStyles: Record<ModelCatalogEntry['tier'], string> = {
    fast: 'border-emerald-300/40 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
    medium: 'border-amber-300/40 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    slow: 'border-red-300/40 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
};

const tierLabels: Record<ModelCatalogEntry['tier'], string> = {
    fast: 'Fast',
    medium: 'Medium',
    slow: 'Slow',
};

export function ModelPicker({
    id,
    label,
    provider,
    value,
    onChange,
    catalog,
    initialLatency = null,
    placeholder,
    helpText,
}: Props) {
    const { t } = useT();
    const [latency, setLatency] = useState<ModelLatency | null>(initialLatency);
    const [probing, setProbing] = useState(false);
    const [refreshing, setRefreshing] = useState(false);

    const refreshCloudflareModels = async () => {
        setRefreshing(true);

        try {
            const csrf =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';
            const res = await fetch(
                '/settings/system/probe/cloudflare-models',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            const data = (await res.json()) as {
                ok: boolean;
                ids?: string[];
                error?: string | null;
            };

            if (data.ok) {
                toast.success(
                    t('Pulled :count models from Cloudflare. Reload page.', {
                        count: data.ids?.length ?? 0,
                    }),
                );
            } else {
                toast.error(data.error ?? t('Refresh failed.'));
            }
        } catch (e) {
            toast.error(
                (e as Error).message ??
                    t('Could not reach the refresh endpoint.'),
            );
        } finally {
            setRefreshing(false);
        }
    };

    const inCatalog = catalog.find((c) => c.id === value);
    const isCustom = value !== '' && !inCatalog;
    const selectValue = value === '' ? '' : isCustom ? CUSTOM_VALUE : value;
    const active = inCatalog ?? null;

    const groups = useMemo(() => {
        const buckets: Record<
            'recommended' | 'fast' | 'medium' | 'slow',
            ModelCatalogEntry[]
        > = { recommended: [], fast: [], medium: [], slow: [] };

        for (const entry of catalog) {
            if (entry.recommended) {
                buckets.recommended.push(entry);
                continue;
            }

            buckets[entry.tier].push(entry);
        }

        const sortByTtft = (a: ModelCatalogEntry, b: ModelCatalogEntry) =>
            a.ttft_ms - b.ttft_ms;

        return [
            {
                key: 'recommended',
                label: 'Recommended',
                items: buckets.recommended.sort(sortByTtft),
            },
            {
                key: 'fast',
                label: 'Fast (< 250ms TTFT)',
                items: buckets.fast.sort(sortByTtft),
            },
            {
                key: 'medium',
                label: 'Medium (250-600ms)',
                items: buckets.medium.sort(sortByTtft),
            },
            {
                key: 'slow',
                label: 'Slow (> 600ms)',
                items: buckets.slow.sort(sortByTtft),
            },
        ].filter((g) => g.items.length > 0);
    }, [catalog]);

    const probe = async () => {
        if (!value) {
            toast.error(t('Pick a model first.'));

            return;
        }

        setProbing(true);

        try {
            const csrf =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';
            const res = await fetch('/settings/system/probe/llm-latency', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ provider, model: value, force: true }),
            });
            const data = (await res.json()) as ModelLatency & {
                error?: string | null;
            };
            setLatency(data);

            if (data.ok) {
                toast.success(
                    t('Measured :ms ms TTFT', { ms: data.ttft_ms ?? 0 }),
                );
            } else {
                toast.error(data.error ?? t('Probe failed.'));
            }
        } catch (e) {
            toast.error(
                (e as Error).message ??
                    t('Could not reach the probe endpoint.'),
            );
        } finally {
            setProbing(false);
        }
    };

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex flex-wrap items-center gap-2">
                <Select
                    value={selectValue}
                    onValueChange={(v) => {
                        if (v === CUSTOM_VALUE) {
                            return;
                        }

                        onChange(v);
                        setLatency(null);
                    }}
                >
                    <SelectTrigger id={id} className="min-w-[260px] flex-1">
                        <SelectValue
                            placeholder={placeholder ?? t('Pick a model…')}
                        />
                    </SelectTrigger>
                    <SelectContent className="max-h-[480px]">
                        {groups.map((group) => (
                            <SelectGroup key={group.key}>
                                <SelectLabel className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                    {t(group.label)} · {group.items.length}
                                </SelectLabel>
                                {group.items.map((entry) => (
                                    <SelectItem
                                        key={entry.id}
                                        value={entry.id}
                                        className="py-2"
                                    >
                                        <div className="flex w-full items-center justify-between gap-3">
                                            <div className="flex min-w-0 flex-col">
                                                <span className="flex items-center gap-1.5 truncate text-sm font-medium">
                                                    {entry.label}
                                                    {entry.recommended && (
                                                        <Sparkles className="size-3 text-amber-500" />
                                                    )}
                                                    {entry.supports_tools && (
                                                        <Wrench className="size-3 text-muted-foreground" />
                                                    )}
                                                </span>
                                                <span className="truncate text-[10px] text-muted-foreground">
                                                    {entry.id}
                                                </span>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1.5">
                                                <span
                                                    className={cn(
                                                        'inline-flex items-center gap-0.5 rounded-full border px-1.5 py-0.5 text-[10px] font-medium',
                                                        tierStyles[entry.tier],
                                                    )}
                                                >
                                                    <Gauge className="size-2.5" />
                                                    ~{entry.ttft_ms}ms
                                                </span>
                                                <span className="rounded-full bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                                    {entry.cost === 'free'
                                                        ? 'free'
                                                        : entry.cost}
                                                </span>
                                            </div>
                                        </div>
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        ))}
                        {isCustom && (
                            <SelectGroup>
                                <SelectLabel className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                    {t('Custom')}
                                </SelectLabel>
                                <SelectItem value={CUSTOM_VALUE} disabled>
                                    <div className="flex w-full items-center justify-between gap-3">
                                        <span className="text-sm font-medium">
                                            {value}
                                        </span>
                                        <span className="rounded-full bg-amber-500/20 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-300">
                                            {t('Custom')}
                                        </span>
                                    </div>
                                </SelectItem>
                            </SelectGroup>
                        )}
                    </SelectContent>
                </Select>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={probing || !value}
                    onClick={probe}
                    title={t('Send a 1-token request and measure latency')}
                >
                    {probing ? (
                        <Loader2 className="me-1.5 size-4 animate-spin" />
                    ) : (
                        <Gauge className="me-1.5 size-4" />
                    )}
                    {t('Test connection')}
                </Button>
                {provider === 'cloudflare' && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={refreshing}
                        onClick={refreshCloudflareModels}
                        title={t(
                            'Fetch the latest model list from Cloudflare Workers AI',
                        )}
                    >
                        {refreshing ? (
                            <Loader2 className="me-1.5 size-4 animate-spin" />
                        ) : (
                            <Cloud className="me-1.5 size-4" />
                        )}
                        {t('Refresh from Cloudflare')}
                    </Button>
                )}
            </div>
            <div className="mt-1 flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
                {active && (
                    <span>
                        {t('Estimated TTFT:')}{' '}
                        <strong className="text-foreground">
                            ~{active.ttft_ms}ms
                        </strong>{' '}
                        ({tierLabels[active.tier]})
                    </span>
                )}
                {latency?.ok && latency.ttft_ms !== null && (
                    <span className="text-emerald-700 dark:text-emerald-400">
                        {t('Measured:')} <strong>{latency.ttft_ms}ms</strong>{' '}
                        {latency.total_ms !== null && (
                            <>· total {latency.total_ms}ms</>
                        )}
                    </span>
                )}
                {latency && !latency.ok && latency.error && (
                    <span className="text-red-700 dark:text-red-400">
                        {t('Probe failed:')} {latency.error}
                    </span>
                )}
                {active?.notes && (
                    <span className="basis-full text-muted-foreground">
                        {active.notes}
                    </span>
                )}
            </div>
            {isCustom && (
                <Input
                    id={`${id}-custom`}
                    value={value}
                    onChange={(e) => {
                        onChange(e.target.value);
                        setLatency(null);
                    }}
                    placeholder={placeholder}
                    className="mt-1"
                />
            )}
            {!isCustom && helpText && (
                <p className="text-[11px] text-muted-foreground">{helpText}</p>
            )}
        </div>
    );
}
