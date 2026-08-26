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
    radius: number;
    agentName: string;
    launcherLabel: string;
    /**
     * Per-agent lead-form schema (#34). Null falls back to the
     * default Name + Email shape via `resolveSchema()`.
     */
    schema?: LeadFormField[] | null;
    onClose: () => void;
    scheme?: 'light' | 'dark';
};

/**
 * Pre-chat lead gate — replaces the chat surface when the agent
 * has `require_lead_before_chat` on. Capture lands server-side via
 * the existing /api/v1/widget/leads endpoint, and we flip
 * `state.leadCaptured` so the chat surface unlocks on the same
 * panel without a reload.
 *
 * The form fields come from the agent's `lead_form_fields` schema
 * (#34); same renderer the inline `<LeadForm/>` uses, so a buyer
 * who builds a 5-field form sees the same shape in both mount
 * points.
 */
export function PreChatGate({
    api,
    jwt,
    accent,
    radius,
    agentName,
    launcherLabel,
    schema,
    onClose,
    scheme,
}: Props) {
    const isDark = scheme === 'dark';
    const surface = isDark ? '#0f172a' : '#ffffff';
    const headerBorder = isDark ? '#1f2937' : '#f1f5f9';
    const closeText = isDark ? '#94a3b8' : '#6b7280';
    const titleText = isDark ? '#e2e8f0' : '#0f172a';
    const subText = isDark ? '#94a3b8' : '#64748b';
    const errorText = isDark ? '#fca5a5' : '#b91c1c';
    const fields = resolveSchema(schema);
    const [values, setValues] = useState<Record<string, string | boolean>>({});
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: Event) => {
        e.preventDefault();

        // Hard-required-field gate, including checkboxes (HTML form
        // submission alone doesn't reliably surface a missing
        // checkbox-required state on every browser).
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
            store.set({ leadCaptured: true });
        } catch {
            setError(t('Could not save your details. Please try again.'));
            setSubmitting(false);
        }
    };

    return (
        <div
            style={{
                width: 'min(560px, 95vw)',
                background: surface,
                color: titleText,
                borderRadius: radius,
                boxShadow: '0 24px 48px rgba(0,0,0,0.18)',
                overflow: 'hidden',
                animation: 'pitchbar-rise 200ms ease-out',
            }}
        >
            <div
                style={{
                    padding: '10px 14px',
                    borderBottom: `1px solid ${headerBorder}`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 8,
                }}
            >
                <strong style={{ fontSize: 13 }}>{agentName}</strong>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('Close')}
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        width: 26,
                        height: 26,
                        borderRadius: '50%',
                        border: 'none',
                        background: 'transparent',
                        color: closeText,
                        fontSize: 18,
                        lineHeight: 1,
                        cursor: 'pointer',
                    }}
                >
                    ×
                </button>
            </div>

            <form
                onSubmit={submit}
                style={{
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                }}
            >
                <div>
                    <p
                        style={{
                            margin: 0,
                            fontSize: 15,
                            fontWeight: 600,
                            color: titleText,
                        }}
                    >
                        {launcherLabel}
                    </p>
                    <p
                        style={{
                            margin: '4px 0 0',
                            fontSize: 12,
                            color: subText,
                        }}
                    >
                        {t(
                            'Share your details so we can pick up where the chat leaves off.',
                        )}
                    </p>
                </div>

                <DynamicLeadFields
                    schema={fields}
                    values={values}
                    onChange={(key, value) =>
                        setValues((v) => ({ ...v, [key]: value }))
                    }
                />

                {error && (
                    <p
                        style={{
                            margin: 0,
                            color: errorText,
                            fontSize: 12,
                        }}
                    >
                        {error}
                    </p>
                )}

                <button
                    type="submit"
                    disabled={submitting}
                    style={{
                        background: accent,
                        color: 'white',
                        border: 'none',
                        borderRadius: 8,
                        padding: '12px 14px',
                        fontSize: 14,
                        fontWeight: 600,
                        cursor: submitting ? 'wait' : 'pointer',
                    }}
                >
                    {submitting ? t('Starting chat…') : t('Start chat')}
                </button>
            </form>
        </div>
    );
}
