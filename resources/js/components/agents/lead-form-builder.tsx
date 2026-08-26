import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useT } from '@/lib/i18n';

export type LeadFormFieldType =
    | 'text'
    | 'email'
    | 'tel'
    | 'textarea'
    | 'select'
    | 'checkbox';

export type LeadFormField = {
    key: string;
    label: string;
    type: LeadFormFieldType;
    required?: boolean;
    placeholder?: string | null;
    maxlength?: number | null;
    options?: string[];
};

const RESERVED_KEYS = new Set(['email', 'name', 'phone']);
const PII_FLAG_KEYS = new Set([
    'ssn',
    'passport',
    'national_id',
    'tax_id',
    'credit_card',
    'cc_number',
    'date_of_birth',
    'dob',
]);

const MAX_FIELDS = 12;

type Props = {
    value: LeadFormField[] | null;
    onChange: (fields: LeadFormField[] | null) => void;
};

/**
 * Per-agent lead-form schema editor (#34). Stores a list of field
 * definitions on `agents.lead_form_fields`; the widget renders the
 * same list in both `<LeadForm/>` and `<PreChatGate/>`. Null = use
 * the default Name + Email shape.
 *
 * Reordering uses simple ↑/↓ buttons rather than dnd-kit to keep
 * the bundle small and the surface accessible — drag-drop on a
 * narrow form-builder card was a UX overreach.
 */
export function LeadFormBuilder({ value, onChange }: Props) {
    const { t } = useT();
    const TYPE_OPTIONS: { value: LeadFormFieldType; label: string }[] = [
        { value: 'text', label: t('Text') },
        { value: 'email', label: t('Email') },
        { value: 'tel', label: t('Phone') },
        { value: 'textarea', label: t('Long text') },
        { value: 'select', label: t('Dropdown') },
        { value: 'checkbox', label: t('Checkbox') },
    ];
    const PRESETS: { id: string; label: string; fields: LeadFormField[] }[] = [
        {
            id: 'classic',
            label: t('Classic — Name + Email'),
            fields: [
                {
                    key: 'name',
                    label: t('Your name'),
                    type: 'text',
                    required: false,
                    placeholder: t('Optional'),
                },
                {
                    key: 'email',
                    label: t('Email'),
                    type: 'email',
                    required: true,
                    placeholder: 'email@example.com',
                },
            ],
        },
        {
            id: 'b2b',
            label: t('B2B SaaS — Name, Work email, Company, Team size'),
            fields: [
                {
                    key: 'name',
                    label: t('Your name'),
                    type: 'text',
                    required: true,
                },
                {
                    key: 'email',
                    label: t('Work email'),
                    type: 'email',
                    required: true,
                    placeholder: 'name@company.com',
                },
                {
                    key: 'company',
                    label: t('Company'),
                    type: 'text',
                    required: true,
                    maxlength: 120,
                },
                {
                    key: 'team_size',
                    label: t('Team size'),
                    type: 'select',
                    required: false,
                    options: ['1-10', '11-50', '51-200', '201+'],
                },
            ],
        },
        {
            id: 'support',
            label: t('Support — Email + Order ID + Issue category'),
            fields: [
                {
                    key: 'email',
                    label: t('Email'),
                    type: 'email',
                    required: true,
                },
                {
                    key: 'order_id',
                    label: t('Order ID'),
                    type: 'text',
                    required: false,
                    placeholder: t('e.g. #12345'),
                },
                {
                    key: 'issue_category',
                    label: t('Issue category'),
                    type: 'select',
                    required: true,
                    options: [
                        t('Order status'),
                        t('Refund / return'),
                        t('Product question'),
                        t('Other'),
                    ],
                },
            ],
        },
        {
            id: 'gdpr',
            label: t('GDPR-friendly — Email + Consent checkbox'),
            fields: [
                {
                    key: 'email',
                    label: t('Email'),
                    type: 'email',
                    required: true,
                },
                {
                    key: 'consent',
                    label: t('I agree to be contacted about my enquiry'),
                    type: 'checkbox',
                    required: true,
                },
            ],
        },
    ];
    const fields = value ?? [];
    const customized = value !== null;
    const [openIdx, setOpenIdx] = useState<number | null>(
        customized && fields.length > 0 ? 0 : null,
    );

    const apply = (next: LeadFormField[]) => {
        onChange(next);
    };

    const addField = () => {
        if (fields.length >= MAX_FIELDS) {
            return;
        }

        const newField: LeadFormField = {
            key: `field_${fields.length + 1}`,
            label: t('New field'),
            type: 'text',
            required: false,
        };
        apply([...fields, newField]);
        setOpenIdx(fields.length);
    };

    const removeField = (i: number) => {
        const next = fields.filter((_, idx) => idx !== i);
        apply(next.length === 0 ? [] : next);
        setOpenIdx(null);
    };

    const moveField = (i: number, dir: -1 | 1) => {
        const j = i + dir;

        if (j < 0 || j >= fields.length) {
            return;
        }

        const next = fields.slice();
        [next[i], next[j]] = [next[j], next[i]];
        apply(next);
        setOpenIdx(j);
    };

    const updateField = (i: number, patch: Partial<LeadFormField>) => {
        const next = fields.slice();
        next[i] = { ...next[i], ...patch };
        apply(next);
    };

    const usePreset = (presetId: string) => {
        const preset = PRESETS.find((p) => p.id === presetId);

        if (preset) {
            apply(preset.fields.map((f) => ({ ...f })));
            setOpenIdx(0);
        }
    };

    const reset = () => {
        onChange(null);
        setOpenIdx(null);
    };

    return (
        <div className="grid gap-3">
            {!customized ? (
                <div className="rounded-md border bg-muted/20 p-3">
                    <p className="text-sm text-foreground">
                        {t('Default shape:')}{' '}
                        <strong>{t('Name + Email')}</strong>.
                    </p>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                        {t(
                            'Visitors see a name field (optional) and an email field (required). Customize to ask for company, order ID, consent, or whatever your sales / support flow needs.',
                        )}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => apply([])}
                        >
                            {t('Customize')}
                        </Button>
                        <Select onValueChange={usePreset}>
                            <SelectTrigger className="w-auto">
                                <SelectValue
                                    placeholder={t('Start from a preset…')}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {PRESETS.map((p) => (
                                    <SelectItem key={p.id} value={p.id}>
                                        {p.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            ) : (
                <>
                    {fields.length === 0 && (
                        <div className="rounded-md border bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                            {t(
                                'No fields yet — visitors will see an empty form. Add at least an Email field.',
                            )}
                        </div>
                    )}
                    <ul className="grid gap-2">
                        {fields.map((field, i) => {
                            const isOpen = openIdx === i;
                            const isReserved = RESERVED_KEYS.has(field.key);
                            const isPiiFlag = PII_FLAG_KEYS.has(field.key);

                            return (
                                <li
                                    key={i}
                                    className="rounded-md border bg-background"
                                >
                                    <div className="flex items-center gap-2 px-3 py-2">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOpenIdx(isOpen ? null : i)
                                            }
                                            className="flex flex-1 flex-wrap items-center gap-2 text-start"
                                        >
                                            <span className="min-w-0 truncate font-mono text-[11px] text-muted-foreground">
                                                {field.key}
                                            </span>
                                            <span className="min-w-0 truncate text-sm font-medium text-foreground">
                                                {field.label}
                                            </span>
                                            <span className="shrink-0 rounded-full border bg-muted/50 px-2 py-0.5 text-[10px] text-muted-foreground uppercase">
                                                {field.type}
                                            </span>
                                            {field.required && (
                                                <span className="shrink-0 rounded-full border border-rose-300 bg-rose-50 px-2 py-0.5 text-[10px] font-medium text-rose-700 dark:border-rose-500/30 dark:bg-rose-950/40 dark:text-rose-300">
                                                    {t('required')}
                                                </span>
                                            )}
                                            {isReserved && (
                                                <span
                                                    className="shrink-0 rounded-full border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                    title={t(
                                                        'Reserved key — maps onto Lead.email / .name / .phone columns',
                                                    )}
                                                >
                                                    {t('reserved')}
                                                </span>
                                            )}
                                            {isPiiFlag && (
                                                <span
                                                    className="shrink-0 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/40 dark:text-amber-200"
                                                    title={t(
                                                        'High-sensitivity field name. Are you sure you want to store this?',
                                                    )}
                                                >
                                                    {t('PII')}
                                                </span>
                                            )}
                                            <span className="ms-auto" />
                                        </button>
                                        <div className="flex items-center gap-1">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                disabled={i === 0}
                                                onClick={() => moveField(i, -1)}
                                                aria-label={t('Move up')}
                                            >
                                                <ChevronUp className="size-4" />
                                            </Button>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                disabled={
                                                    i === fields.length - 1
                                                }
                                                onClick={() => moveField(i, 1)}
                                                aria-label={t('Move down')}
                                            >
                                                <ChevronDown className="size-4" />
                                            </Button>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                onClick={() => removeField(i)}
                                                aria-label={t('Remove field')}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    {isOpen && (
                                        <div className="grid gap-3 border-t p-3">
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                <div className="grid gap-1">
                                                    <Label
                                                        htmlFor={`field-${i}-key`}
                                                    >
                                                        {t('Key')}
                                                    </Label>
                                                    <Input
                                                        id={`field-${i}-key`}
                                                        value={field.key}
                                                        onChange={(e) =>
                                                            updateField(i, {
                                                                key: e.target.value
                                                                    .toLowerCase()
                                                                    .replace(
                                                                        /[^a-z0-9_]/g,
                                                                        '_',
                                                                    ),
                                                            })
                                                        }
                                                        className="font-mono text-xs"
                                                    />
                                                    <p className="text-[11px] text-muted-foreground">
                                                        {t(
                                                            'Lowercase, digits, underscores. Reserved keys',
                                                        )}{' '}
                                                        <code>email</code>,{' '}
                                                        <code>name</code>,{' '}
                                                        <code>phone</code>{' '}
                                                        {t(
                                                            'map onto Lead columns.',
                                                        )}
                                                    </p>
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label
                                                        htmlFor={`field-${i}-label`}
                                                    >
                                                        {t('Label')}
                                                    </Label>
                                                    <Input
                                                        id={`field-${i}-label`}
                                                        value={field.label}
                                                        onChange={(e) =>
                                                            updateField(i, {
                                                                label: e.target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                </div>
                                            </div>
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                <div className="grid gap-1">
                                                    <Label
                                                        htmlFor={`field-${i}-type`}
                                                    >
                                                        {t('Type')}
                                                    </Label>
                                                    <Select
                                                        value={field.type}
                                                        onValueChange={(v) =>
                                                            updateField(i, {
                                                                type: v as LeadFormFieldType,
                                                            })
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            id={`field-${i}-type`}
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {TYPE_OPTIONS.map(
                                                                (t) => (
                                                                    <SelectItem
                                                                        key={
                                                                            t.value
                                                                        }
                                                                        value={
                                                                            t.value
                                                                        }
                                                                    >
                                                                        {
                                                                            t.label
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="flex items-end">
                                                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                                                        <input
                                                            type="checkbox"
                                                            checked={
                                                                field.required ===
                                                                true
                                                            }
                                                            onChange={(e) =>
                                                                updateField(i, {
                                                                    required: (
                                                                        e.target as HTMLInputElement
                                                                    ).checked,
                                                                })
                                                            }
                                                            className="size-4 rounded border-border focus:ring-2 focus:ring-ring/40"
                                                        />
                                                        {t('Required')}
                                                    </label>
                                                </div>
                                            </div>
                                            {field.type !== 'checkbox' && (
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    <div className="grid gap-1">
                                                        <Label
                                                            htmlFor={`field-${i}-placeholder`}
                                                        >
                                                            {t('Placeholder')}
                                                        </Label>
                                                        <Input
                                                            id={`field-${i}-placeholder`}
                                                            value={
                                                                field.placeholder ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                updateField(i, {
                                                                    placeholder:
                                                                        e.target
                                                                            .value,
                                                                })
                                                            }
                                                        />
                                                    </div>
                                                    {(field.type === 'text' ||
                                                        field.type ===
                                                            'textarea') && (
                                                        <div className="grid gap-1">
                                                            <Label
                                                                htmlFor={`field-${i}-maxlength`}
                                                            >
                                                                {t(
                                                                    'Max length',
                                                                )}
                                                            </Label>
                                                            <Input
                                                                id={`field-${i}-maxlength`}
                                                                type="number"
                                                                min={1}
                                                                max={4000}
                                                                value={
                                                                    field.maxlength ??
                                                                    ''
                                                                }
                                                                onChange={(e) =>
                                                                    updateField(
                                                                        i,
                                                                        {
                                                                            maxlength:
                                                                                e
                                                                                    .target
                                                                                    .value ===
                                                                                ''
                                                                                    ? null
                                                                                    : parseInt(
                                                                                          e
                                                                                              .target
                                                                                              .value,
                                                                                          10,
                                                                                      ),
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                            {field.type === 'select' && (
                                                <div className="grid gap-1">
                                                    <Label
                                                        htmlFor={`field-${i}-options`}
                                                    >
                                                        {t(
                                                            'Options (one per line)',
                                                        )}
                                                    </Label>
                                                    <textarea
                                                        id={`field-${i}-options`}
                                                        rows={4}
                                                        value={(
                                                            field.options ?? []
                                                        ).join('\n')}
                                                        onChange={(e) =>
                                                            updateField(i, {
                                                                options:
                                                                    e.target.value
                                                                        .split(
                                                                            '\n',
                                                                        )
                                                                        .map(
                                                                            (
                                                                                s,
                                                                            ) =>
                                                                                s.trim(),
                                                                        )
                                                                        .filter(
                                                                            (
                                                                                s,
                                                                            ) =>
                                                                                s !==
                                                                                '',
                                                                        ),
                                                            })
                                                        }
                                                        className="rounded-md border bg-background px-3 py-2 text-sm"
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={fields.length >= MAX_FIELDS}
                            onClick={addField}
                        >
                            <Plus className="size-3.5" /> {t('Add field')}
                        </Button>
                        <div className="flex items-center gap-2">
                            <Select onValueChange={usePreset}>
                                <SelectTrigger className="w-auto">
                                    <SelectValue
                                        placeholder={t('Replace with preset…')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {PRESETS.map((p) => (
                                        <SelectItem key={p.id} value={p.id}>
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={reset}
                            >
                                {t('Reset to default')}
                            </Button>
                        </div>
                    </div>

                    <p className="text-[11px] text-muted-foreground tabular-nums">
                        {t(
                            ':used/:max fields used. Required fields are enforced both by the widget and on the server.',
                            { used: fields.length, max: MAX_FIELDS },
                        )}
                    </p>
                </>
            )}
        </div>
    );
}
