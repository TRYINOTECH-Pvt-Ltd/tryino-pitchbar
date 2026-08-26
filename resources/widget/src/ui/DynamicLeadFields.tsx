import type { LeadFormField } from '../core/api';
import { t } from '../core/i18n';

/**
 * Stateless renderer for a list of `LeadFormField` definitions
 * coming from the agent's `lead_form_fields` schema (#34). Used
 * inside both `<LeadForm/>` (mid-conversation) and `<PreChatGate/>`
 * (pre-chat gate, #10) so the buyer's custom schema renders the
 * same way in both mount points.
 *
 * Field types in v1: text / email / tel / textarea / select /
 * checkbox. The `key` becomes the form-state map key; the parent
 * decides what to do with the values on submit (typically: split
 * out reserved `email` / `name` / `phone` to top-level Lead columns
 * and pass everything else through as `fields`).
 *
 * One field per row keeps tap targets honest on mobile.
 */
type Values = Record<string, string | boolean>;

type Props = {
    schema: LeadFormField[];
    values: Values;
    onChange: (key: string, value: string | boolean) => void;
};

const inputBase = {
    border: '1px solid #e5e7eb',
    borderRadius: 8,
    padding: '10px 12px',
    // 16px keeps iOS Safari from auto-zooming on focus.
    fontSize: 16,
    outline: 'none',
    fontFamily: 'inherit',
    width: '100%',
    boxSizing: 'border-box' as const,
};

const labelBase = {
    fontSize: 12,
    fontWeight: 500,
    color: '#334155',
    marginBottom: 4,
};

export function DynamicLeadFields({ schema, values, onChange }: Props) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {schema.map((field) => (
                <FieldRow
                    key={field.key}
                    field={field}
                    value={values[field.key]}
                    onChange={(v) => onChange(field.key, v)}
                />
            ))}
        </div>
    );
}

function FieldRow({
    field,
    value,
    onChange,
}: {
    field: LeadFormField;
    value: string | boolean | undefined;
    onChange: (v: string | boolean) => void;
}) {
    const id = `pb-lead-${field.key}`;

    if (field.type === 'checkbox') {
        const checked = value === true;

        return (
            <label
                htmlFor={id}
                style={{
                    display: 'flex',
                    alignItems: 'flex-start',
                    gap: 8,
                    fontSize: 13,
                    color: '#0f172a',
                    cursor: 'pointer',
                }}
            >
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    required={field.required === true}
                    onChange={(e) =>
                        onChange((e.target as HTMLInputElement).checked)
                    }
                    style={{ marginTop: 2, flexShrink: 0 }}
                />
                <span>
                    {field.label}
                    {field.required ? (
                        <span style={{ color: '#b91c1c' }}> *</span>
                    ) : null}
                </span>
            </label>
        );
    }

    if (field.type === 'select') {
        return (
            <div style={{ display: 'flex', flexDirection: 'column' }}>
                <label htmlFor={id} style={labelBase}>
                    {field.label}
                    {field.required ? (
                        <span style={{ color: '#b91c1c' }}> *</span>
                    ) : null}
                </label>
                <select
                    id={id}
                    required={field.required === true}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(e) =>
                        onChange((e.target as HTMLSelectElement).value)
                    }
                    style={{ ...inputBase, background: 'white' }}
                >
                    <option value="" disabled>
                        {field.placeholder ?? t('Select an option')}
                    </option>
                    {(field.options ?? []).map((opt) => (
                        <option key={opt} value={opt}>
                            {opt}
                        </option>
                    ))}
                </select>
            </div>
        );
    }

    if (field.type === 'textarea') {
        return (
            <div style={{ display: 'flex', flexDirection: 'column' }}>
                <label htmlFor={id} style={labelBase}>
                    {field.label}
                    {field.required ? (
                        <span style={{ color: '#b91c1c' }}> *</span>
                    ) : null}
                </label>
                <textarea
                    id={id}
                    required={field.required === true}
                    placeholder={field.placeholder ?? ''}
                    maxLength={field.maxlength ?? undefined}
                    value={typeof value === 'string' ? value : ''}
                    onInput={(e) =>
                        onChange((e.target as HTMLTextAreaElement).value)
                    }
                    rows={3}
                    style={{ ...inputBase, resize: 'vertical' }}
                />
            </div>
        );
    }

    // text / email / tel
    const inputType =
        field.type === 'email'
            ? 'email'
            : field.type === 'tel'
              ? 'tel'
              : 'text';

    return (
        <div style={{ display: 'flex', flexDirection: 'column' }}>
            <label htmlFor={id} style={labelBase}>
                {field.label}
                {field.required ? (
                    <span style={{ color: '#b91c1c' }}> *</span>
                ) : null}
            </label>
            <input
                id={id}
                type={inputType}
                required={field.required === true}
                placeholder={field.placeholder ?? ''}
                maxLength={field.maxlength ?? undefined}
                autoComplete={
                    field.type === 'email'
                        ? 'email'
                        : field.type === 'tel'
                          ? 'tel'
                          : field.key === 'name'
                            ? 'name'
                            : 'off'
                }
                value={typeof value === 'string' ? value : ''}
                onInput={(e) => onChange((e.target as HTMLInputElement).value)}
                style={inputBase}
            />
        </div>
    );
}

/**
 * Server-reserved keys that map onto Lead columns directly. The
 * widget submits these at the TOP LEVEL of the captureLead payload
 * (so existing analytics queries on `email` / `name` / `phone`
 * keep working) and everything else goes into the `fields` map.
 */
export const RESERVED_LEAD_KEYS = ['email', 'name', 'phone'] as const;

export function splitLeadValues(values: Values): {
    email?: string;
    name?: string;
    phone?: string;
    fields: Record<string, string | boolean>;
} {
    const out: {
        email?: string;
        name?: string;
        phone?: string;
        fields: Record<string, string | boolean>;
    } = { fields: {} };

    for (const [key, value] of Object.entries(values)) {
        if (key === 'email' && typeof value === 'string') {
            out.email = value.trim();
        } else if (key === 'name' && typeof value === 'string') {
            const trimmed = value.trim();

            if (trimmed !== '') {
                out.name = trimmed;
            }
        } else if (key === 'phone' && typeof value === 'string') {
            const trimmed = value.trim();

            if (trimmed !== '') {
                out.phone = trimmed;
            }
        } else if (value !== undefined && value !== '' && value !== false) {
            out.fields[key] = value;
        }
    }

    return out;
}

/**
 * Default schema applied when an agent has no custom
 * `lead_form_fields` set — keeps the legacy two-field form working
 * unchanged.
 */
/**
 * Default schema, evaluated lazily so labels + placeholders reflect
 * the current widget locale. The translator's copy map is hydrated
 * before the first render of `<LeadForm/>` / `<PreChatGate/>` so
 * calling `t()` here is safe.
 */
export function defaultLeadSchema(): LeadFormField[] {
    return [
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
    ];
}

export function resolveSchema(
    raw: LeadFormField[] | null | undefined,
): LeadFormField[] {
    if (Array.isArray(raw) && raw.length > 0) {
        return raw;
    }

    return defaultLeadSchema();
}
