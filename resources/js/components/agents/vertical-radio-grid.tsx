import { Check } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { VERTICALS } from '@/lib/verticals';
import type { VerticalId } from '@/lib/verticals';

type Props = {
    value: VerticalId | null;
    onChange: (id: VerticalId) => void;
    detectedId?: VerticalId | null;
    name?: string;
    className?: string;
};

/**
 * Vertical radio-card grid. Implemented as styled native
 * <input type="radio"> elements (no extra dependency) so the whole grid
 * is a single labelled fieldset and works with form serialization.
 */
export function VerticalRadioGrid({
    value,
    onChange,
    detectedId,
    name = 'site_type',
    className,
}: Props) {
    const { t } = useT();

    return (
        <fieldset className={cn('grid gap-2 sm:grid-cols-2', className)}>
            {VERTICALS.map((v) => {
                const Icon = v.icon;
                const checked = value === v.id;
                const isDetected = detectedId === v.id;

                return (
                    <label
                        key={v.id}
                        htmlFor={`vertical-${v.id}`}
                        className={cn(
                            'group relative flex cursor-pointer items-start gap-3 rounded-lg border bg-background p-3 transition',
                            'hover:border-foreground/30 hover:bg-muted/40',
                            checked
                                ? 'border-foreground/40 bg-muted/30 ring-1 ring-foreground/15'
                                : 'border-border',
                        )}
                    >
                        <input
                            id={`vertical-${v.id}`}
                            type="radio"
                            name={name}
                            value={v.id}
                            checked={checked}
                            onChange={() => onChange(v.id)}
                            className="sr-only"
                        />
                        <span
                            className={cn(
                                'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md border',
                                checked
                                    ? 'border-foreground/30 bg-foreground/5'
                                    : 'border-border bg-muted/40',
                            )}
                        >
                            <Icon className="size-4" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-2">
                                <span className="text-sm font-medium">
                                    {t(v.name)}
                                </span>
                                {isDetected && (
                                    <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-emerald-700 uppercase">
                                        {t('Detected')}
                                    </span>
                                )}
                            </span>
                            <span className="mt-1 block text-xs text-muted-foreground">
                                {t(v.shortDesc)}
                            </span>
                        </span>
                        {checked && (
                            <Check className="size-4 shrink-0 text-foreground" />
                        )}
                    </label>
                );
            })}
        </fieldset>
    );
}
