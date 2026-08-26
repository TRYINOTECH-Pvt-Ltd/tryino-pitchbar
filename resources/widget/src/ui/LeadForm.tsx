import { useState } from 'preact/hooks';
import type { LeadFormField, WidgetApi } from '../core/api';
import { t } from '../core/i18n';
import { store } from '../core/store';
import {
    DynamicLeadFields,
    resolveSchema,
    splitLeadValues,
} from './DynamicLeadFields';

type Props = {
    api: WidgetApi;
    jwt: string;
    accent: string;
    /**
     * Per-agent lead-form schema (#34). Null falls back to the
     * default Name + Email shape via `resolveSchema()`.
     */
    schema?: LeadFormField[] | null;
    scheme?: 'light' | 'dark';
};

export function LeadForm({ api, jwt, accent, schema, scheme }: Props) {
    const isDark = scheme === 'dark';
    const formBorder = isDark ? '#1f2937' : '#e5e7eb';
    const formBg = isDark ? '#1e293b' : '#f9fafb';
    const errorText = isDark ? '#fca5a5' : '#b91c1c';
    const dismissBg = isDark ? '#0f172a' : '#ffffff';
    const dismissText = isDark ? '#e2e8f0' : '#334155';
    const dismissBorder = isDark ? '#1f2937' : '#cbd5e1';
    const fields = resolveSchema(schema);
    const [values, setValues] = useState<Record<string, string | boolean>>({});
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: Event) => {
        e.preventDefault();

        // Required-field check — the input `required` attribute
        // already handles HTML form submission, but we double-check
        // for checkbox-required (browsers don't always mark these
        // invalid before submit).
        for (const field of fields) {
            if (field.required !== true) {
                continue;
            }

            const v = values[field.key];

            if (
                (field.type === 'checkbox' && v !== true) ||
                (field.type !== 'checkbox' &&
                    (typeof v !== 'string' || v.trim() === ''))
            ) {
                setError(t('Please fill in ":label".', { label: field.label }));

                return;
            }
        }

        const payload = splitLeadValues(values);

        if (!payload.email || !payload.email.includes('@')) {
            setError(t('Please enter a valid email address.'));

            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            await api.captureLead(jwt, payload);
            store.set({ leadCaptured: true, leadFormOpen: false });
        } catch {
            setError(t('Could not save your details. Please try again.'));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <form
            onSubmit={submit}
            style={{
                margin: '8px 16px',
                padding: 12,
                border: `1px solid ${formBorder}`,
                borderRadius: 12,
                background: formBg,
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
                flexShrink: 0,
            }}
        >
            <p style={{ margin: 0, fontSize: 13, fontWeight: 500 }}>
                {t("Leave your details — we'll get back to you.")}
            </p>

            <DynamicLeadFields
                schema={fields}
                values={values}
                onChange={(key, value) =>
                    setValues((v) => ({ ...v, [key]: value }))
                }
            />

            {error && (
                <p style={{ margin: 0, color: errorText, fontSize: 12 }}>
                    {error}
                </p>
            )}

            <div style={{ display: 'flex', gap: 8 }}>
                <button
                    type="submit"
                    disabled={submitting}
                    style={{
                        flex: 1,
                        background: accent,
                        color: 'white',
                        border: 'none',
                        borderRadius: 8,
                        padding: '10px 14px',
                        fontSize: 13,
                        fontWeight: 600,
                        cursor: submitting ? 'wait' : 'pointer',
                    }}
                >
                    {submitting ? t('Sending…') : t('Send')}
                </button>
                <button
                    type="button"
                    onClick={() => store.set({ leadFormOpen: false })}
                    style={{
                        background: dismissBg,
                        color: dismissText,
                        border: `1px solid ${dismissBorder}`,
                        borderRadius: 8,
                        padding: '10px 14px',
                        fontSize: 13,
                        fontWeight: 600,
                        cursor: 'pointer',
                    }}
                >
                    {t('Dismiss')}
                </button>
            </div>
        </form>
    );
}
