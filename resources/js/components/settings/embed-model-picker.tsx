import { AlertTriangle, Globe, Ruler, Sparkles } from 'lucide-react';
import { useMemo } from 'react';
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

export type EmbedCatalogEntry = {
    id: string;
    label: string;
    provider: 'cloudflare' | 'openai' | 'openrouter';
    dimensions: number;
    max_input_tokens: number;
    languages: string;
    cost: string;
    recommended: boolean;
    notes: string;
};

type Props = {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    catalog: EmbedCatalogEntry[];
    currentVectorDim: number;
    placeholder?: string;
};

const CUSTOM_VALUE = '__custom__';

const languageLabels: Record<string, string> = {
    en: 'English',
    multilingual: 'Multilingual',
    ja: 'Japanese',
};

const languageStyles: Record<string, string> = {
    en: 'border-sky-300/40 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300',
    multilingual:
        'border-violet-300/40 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300',
    ja: 'border-rose-300/40 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300',
};

export function EmbedModelPicker({
    id,
    label,
    value,
    onChange,
    catalog,
    currentVectorDim,
    placeholder,
}: Props) {
    const { t } = useT();

    const inCatalog = catalog.find((c) => c.id === value);
    const isCustom = value !== '' && !inCatalog;
    const selectValue = value === '' ? '' : isCustom ? CUSTOM_VALUE : value;
    const active = inCatalog ?? null;

    const groups = useMemo(() => {
        const recommended = catalog.filter((e) => e.recommended);
        const rest = catalog.filter((e) => !e.recommended);

        return [
            { key: 'recommended', label: 'Recommended', items: recommended },
            { key: 'other', label: 'All models', items: rest },
        ].filter((g) => g.items.length > 0);
    }, [catalog]);

    const dimensionWillChange =
        active !== null && active.dimensions !== currentVectorDim;

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={selectValue}
                onValueChange={(v) => {
                    if (v === CUSTOM_VALUE) {
                        return;
                    }

                    onChange(v);
                }}
            >
                <SelectTrigger id={id} className="min-w-[260px]">
                    <SelectValue
                        placeholder={placeholder ?? t('Pick an embed model…')}
                    />
                </SelectTrigger>
                <SelectContent className="max-h-[440px]">
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
                                            </span>
                                            <span className="truncate text-[10px] text-muted-foreground">
                                                {entry.id}
                                            </span>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1.5">
                                            <span className="inline-flex items-center gap-0.5 rounded-full border border-border bg-muted/40 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground tabular-nums">
                                                <Ruler className="size-2.5" />
                                                {entry.dimensions}d
                                            </span>
                                            <span
                                                className={cn(
                                                    'inline-flex items-center gap-0.5 rounded-full border px-1.5 py-0.5 text-[10px] font-medium',
                                                    languageStyles[
                                                        entry.languages
                                                    ] ??
                                                        'border-border bg-muted text-muted-foreground',
                                                )}
                                            >
                                                <Globe className="size-2.5" />
                                                {languageLabels[
                                                    entry.languages
                                                ] ?? entry.languages}
                                            </span>
                                            <span className="rounded-full bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                                {entry.cost}
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
            {active && (
                <div className="mt-1 flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
                    <span>
                        <strong className="text-foreground">
                            {active.dimensions}
                        </strong>{' '}
                        {t('dims')}
                    </span>
                    <span>
                        <strong className="text-foreground">
                            {active.max_input_tokens.toLocaleString()}
                        </strong>{' '}
                        {t('max input tokens')}
                    </span>
                    <span>
                        {t('Languages:')}{' '}
                        <strong className="text-foreground">
                            {languageLabels[active.languages] ??
                                active.languages}
                        </strong>
                    </span>
                    <span className="basis-full">{active.notes}</span>
                </div>
            )}
            {dimensionWillChange && active !== null && (
                <div className="mt-2 flex items-start gap-2 rounded-md border border-amber-300/50 bg-amber-50 p-2.5 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <div>
                        <strong className="font-semibold">
                            {t('Vector dimension change')}
                        </strong>{' '}
                        —{' '}
                        {t(
                            'current index uses :current dims, this model emits :next dims. After saving you MUST re-index every source so old vectors stop polluting search results.',
                            {
                                current: currentVectorDim,
                                next: active.dimensions,
                            },
                        )}
                    </div>
                </div>
            )}
            {isCustom && (
                <Input
                    id={`${id}-custom`}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                    className="mt-1"
                />
            )}
        </div>
    );
}
