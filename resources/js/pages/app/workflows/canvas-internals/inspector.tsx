import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import type { BranchCase, BranchMatch, CanvasNode } from './types';

const matchOptions: BranchMatch[] = [
    'equals',
    'contains',
    'starts_with',
    'is_empty',
    'not_empty',
    'default',
];

type Props = {
    node: CanvasNode;
    onChange: (data: Record<string, unknown>) => void;
    onDelete: () => void;
    /**
     * Trigger-specific props are surfaced on the trigger inspector
     * since the trigger isn't an editable step.
     */
    triggerExtras?: {
        keywords: string[];
        match_mode: 'any' | 'all' | 'exact';
        onTriggerChange: (
            patch: Partial<{
                keywords: string[];
                match_mode: 'any' | 'all' | 'exact';
            }>,
        ) => void;
    };
};

export function NodeInspector({
    node,
    onChange,
    onDelete,
    triggerExtras,
}: Props) {
    const { t } = useT();

    if (node.id === 'trigger' && triggerExtras) {
        return <TriggerInspector extras={triggerExtras} />;
    }

    const data = node.data ?? {};

    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold capitalize">
                    {node.type.replace('_', ' ')}
                </h3>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onDelete}
                >
                    <Trash2 className="size-4 text-destructive" />
                </Button>
            </div>

            {node.type === 'message' && (
                <Field label={t('Message text')} htmlFor="ins-text">
                    <textarea
                        id="ins-text"
                        rows={4}
                        className="rounded-md border bg-background p-2 text-sm"
                        value={(data.text as string) ?? ''}
                        onChange={(e) =>
                            onChange({ ...data, text: e.target.value })
                        }
                    />
                </Field>
            )}

            {node.type === 'question' && (
                <>
                    <Field label={t('Question text')} htmlFor="ins-text">
                        <textarea
                            id="ins-text"
                            rows={3}
                            className="rounded-md border bg-background p-2 text-sm"
                            value={(data.text as string) ?? ''}
                            onChange={(e) =>
                                onChange({ ...data, text: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label={t('Save answer to var_name')}
                        htmlFor="ins-var"
                    >
                        <Input
                            id="ins-var"
                            value={(data.var_name as string) ?? ''}
                            onChange={(e) =>
                                onChange({ ...data, var_name: e.target.value })
                            }
                            placeholder="visitor_answer"
                        />
                    </Field>
                </>
            )}

            {node.type === 'branch' && (
                <>
                    <Field label={t('Branch on var')} htmlFor="ins-var">
                        <Input
                            id="ins-var"
                            value={(data.var as string) ?? ''}
                            onChange={(e) =>
                                onChange({ ...data, var: e.target.value })
                            }
                            placeholder="plan_interest"
                        />
                    </Field>
                    <Field label={t('Cases (top to bottom)')} htmlFor="">
                        <BranchCasesEditor
                            cases={(data.cases as BranchCase[]) ?? []}
                            onChange={(cases) => onChange({ ...data, cases })}
                        />
                    </Field>
                    <p className="text-[11px] text-muted-foreground">
                        {t(
                            "Wire each case's end-side handle on the canvas to the next step. The default handle catches anything no other case matched.",
                        )}
                    </p>
                </>
            )}

            {node.type === 'tag_lead' && (
                <Field label={t('Tags (comma-separated)')} htmlFor="ins-tags">
                    <Input
                        id="ins-tags"
                        value={((data.tags as string[]) ?? []).join(', ') ?? ''}
                        onChange={(e) =>
                            onChange({
                                ...data,
                                tags: e.target.value
                                    .split(',')
                                    .map((t) => t.trim())
                                    .filter(Boolean),
                            })
                        }
                        placeholder="pro_intent, qualified"
                    />
                </Field>
            )}

            {node.type === 'webhook' && (
                <>
                    <Field label={t('URL')} htmlFor="ins-url">
                        <Input
                            id="ins-url"
                            value={(data.url as string) ?? ''}
                            onChange={(e) =>
                                onChange({ ...data, url: e.target.value })
                            }
                            placeholder="https://hooks.example.com/in"
                        />
                    </Field>
                    <Field label={t('Method')} htmlFor="ins-method">
                        <select
                            id="ins-method"
                            className="h-9 rounded-md border bg-background px-3 text-sm"
                            value={(
                                (data.method as string) ?? 'POST'
                            ).toString()}
                            onChange={(e) =>
                                onChange({ ...data, method: e.target.value })
                            }
                        >
                            <option value="POST">POST</option>
                            <option value="GET">GET</option>
                        </select>
                    </Field>
                </>
            )}

            {node.type === 'escalate' && (
                <Field label={t('Goodbye message')} htmlFor="ins-text">
                    <textarea
                        id="ins-text"
                        rows={3}
                        className="rounded-md border bg-background p-2 text-sm"
                        value={(data.text as string) ?? ''}
                        onChange={(e) =>
                            onChange({ ...data, text: e.target.value })
                        }
                    />
                </Field>
            )}
        </div>
    );
}

function TriggerInspector({
    extras,
}: {
    extras: NonNullable<Props['triggerExtras']>;
}) {
    const { t } = useT();

    return (
        <div className="flex flex-col gap-3">
            <h3 className="text-sm font-semibold">{t('Trigger')}</h3>
            <Field label={t('Keywords (comma-separated)')} htmlFor="trg-kw">
                <Input
                    id="trg-kw"
                    value={extras.keywords.join(', ')}
                    onChange={(e) =>
                        extras.onTriggerChange({
                            keywords: e.target.value
                                .split(',')
                                .map((k) => k.trim())
                                .filter(Boolean),
                        })
                    }
                    placeholder={t('pricing, plan, cost')}
                />
            </Field>
            <Field label={t('Match mode')} htmlFor="trg-mode">
                <select
                    id="trg-mode"
                    className="h-9 rounded-md border bg-background px-3 text-sm"
                    value={extras.match_mode}
                    onChange={(e) =>
                        extras.onTriggerChange({
                            match_mode: e.target.value as
                                | 'any'
                                | 'all'
                                | 'exact',
                        })
                    }
                >
                    <option value="any">{t('any (substring)')}</option>
                    <option value="all">{t('all (every keyword)')}</option>
                    <option value="exact">{t('exact (verbatim)')}</option>
                </select>
            </Field>
        </div>
    );
}

function Field({
    label,
    htmlFor,
    children,
}: {
    label: string;
    htmlFor: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor} className="text-xs">
                {label}
            </Label>
            {children}
        </div>
    );
}

function BranchCasesEditor({
    cases,
    onChange,
}: {
    cases: BranchCase[];
    onChange: (next: BranchCase[]) => void;
}) {
    const { t } = useT();
    const update = (i: number, patch: Partial<BranchCase>) => {
        const next = [...cases];
        next[i] = { ...next[i], ...patch };
        onChange(next);
    };
    const remove = (i: number) => onChange(cases.filter((_, idx) => idx !== i));
    const add = () =>
        onChange([
            ...cases,
            { match: 'equals', value: '', go_to: 0 } as BranchCase,
        ]);

    return (
        <div className="grid gap-2">
            {cases.map((c, i) => (
                <div
                    key={i}
                    className="grid grid-cols-[1fr_2fr_auto] items-center gap-2 rounded-md border p-2"
                >
                    <select
                        className="h-8 rounded-md border bg-background px-2 text-xs"
                        value={c.match}
                        onChange={(e) =>
                            update(i, { match: e.target.value as BranchMatch })
                        }
                    >
                        {matchOptions.map((m) => (
                            <option key={m} value={m}>
                                {m}
                            </option>
                        ))}
                    </select>
                    <Input
                        value={c.value ?? ''}
                        disabled={
                            c.match === 'is_empty' ||
                            c.match === 'not_empty' ||
                            c.match === 'default'
                        }
                        onChange={(e) => update(i, { value: e.target.value })}
                        placeholder={t('value')}
                        className="h-8 text-xs"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => remove(i)}
                    >
                        <Trash2 className="size-3.5 text-destructive" />
                    </Button>
                </div>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={add}>
                {t('+ Case')}
            </Button>
        </div>
    );
}
