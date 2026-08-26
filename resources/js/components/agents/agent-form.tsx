import { useForm } from '@inertiajs/react';
import { Bot, Globe2, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
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
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { store as storeAgent, update as updateAgent } from '@/routes/agents';

type AgentValues = {
    name: string;
    language_default: string;
    system_prompt: string;
    confidence_threshold: number;
};

type Props = {
    mode: 'create' | 'edit';
    agentId?: string;
    initial?: Partial<AgentValues>;
    className?: string;
};

// Languages the agent can be pinned to answer in. Codes must stay in
// sync with the `in:` rule in StoreAgentRequest / UpdateAgentRequest.
// Labels are native names so a Dutch admin finds "Nederlands", not "NL".
const LANGUAGES: { code: string; label: string }[] = [
    { code: 'en', label: 'English' },
    { code: 'nl', label: 'Nederlands (Dutch)' },
    { code: 'es', label: 'Español (Spanish)' },
    { code: 'fr', label: 'Français (French)' },
    { code: 'de', label: 'Deutsch (German)' },
    { code: 'pt', label: 'Português (Portuguese)' },
    { code: 'ja', label: '日本語 (Japanese)' },
    { code: 'ar', label: 'العربية (Arabic)' },
    { code: 'zh', label: '中文 (Chinese)' },
];

function FieldError({ children }: { children?: string }) {
    if (!children) {
        return null;
    }

    return <p className="text-xs text-destructive">{children}</p>;
}

function SectionIntro({
    icon,
    eyebrow,
    title,
    description,
}: {
    icon: ReactNode;
    eyebrow: string;
    title: string;
    description: string;
}) {
    return (
        <div className="flex gap-3 lg:block">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground shadow-xs lg:mb-4">
                {icon}
            </span>
            <div className="min-w-0">
                <p className="text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                    {eyebrow}
                </p>
                <h2 className="mt-1 text-sm font-semibold text-foreground">
                    {title}
                </h2>
                <p className="mt-1 max-w-sm text-xs leading-5 text-muted-foreground">
                    {description}
                </p>
            </div>
        </div>
    );
}

export function AgentForm({ mode, agentId, initial = {}, className }: Props) {
    const { t } = useT();
    const form = useForm<AgentValues>({
        name: initial.name ?? '',
        language_default: initial.language_default ?? 'en',
        system_prompt: initial.system_prompt ?? '',
        confidence_threshold: initial.confidence_threshold ?? 0.5,
    });

    const confidenceValue = Number.isFinite(form.data.confidence_threshold)
        ? form.data.confidence_threshold
        : 0.5;
    const confidencePercent = Math.round(confidenceValue * 100);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (mode === 'create') {
            form.post(storeAgent.url());
        } else if (agentId) {
            form.patch(updateAgent.url({ agent: agentId }), {
                preserveScroll: true,
            });
        }
    };

    return (
        <Card className={cn('overflow-hidden border-border/80 p-0', className)}>
            <div className="border-b bg-muted/20 px-4 py-4 sm:px-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-sm font-semibold text-foreground">
                            {t('Agent profile')}
                        </p>
                        <p className="mt-1 max-w-2xl text-xs leading-5 text-muted-foreground">
                            {t(
                                'Control the identity, instruction layer, and retrieval safety threshold that shape every visitor answer.',
                            )}
                        </p>
                    </div>
                    {mode === 'edit' && form.recentlySuccessful ? (
                        <span className="rounded-md border border-emerald-500/25 bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                            {t('Changes saved')}
                        </span>
                    ) : null}
                </div>
            </div>
            <form onSubmit={submit}>
                <section className="grid gap-5 border-b p-4 sm:p-5 lg:grid-cols-[220px_1fr] lg:gap-8">
                    <SectionIntro
                        icon={<Bot className="size-4" />}
                        eyebrow={t('Basics')}
                        title={t('Basics')}
                        description={t(
                            'Name the agent and choose the language it should use by default.',
                        )}
                    />

                    <div className="grid gap-4 sm:grid-cols-[1fr_180px]">
                        <div className="grid gap-1.5">
                            <Label htmlFor="name">{t('Name')}</Label>
                            <Input
                                id="name"
                                className="h-9"
                                placeholder={t('Sales assistant')}
                                value={form.data.name}
                                aria-invalid={Boolean(form.errors.name)}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            <FieldError>{form.errors.name}</FieldError>
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="lang">
                                {t('Default language')}
                            </Label>
                            <Select
                                value={form.data.language_default}
                                onValueChange={(value) =>
                                    form.setData('language_default', value)
                                }
                            >
                                <SelectTrigger id="lang" className="h-9">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {LANGUAGES.map((language) => (
                                        <SelectItem
                                            key={language.code}
                                            value={language.code}
                                        >
                                            {language.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </section>

                <section className="grid gap-5 border-b p-4 sm:p-5 lg:grid-cols-[220px_1fr] lg:gap-8">
                    <SectionIntro
                        icon={<Globe2 className="size-4" />}
                        eyebrow={t('Instructions')}
                        title={t('Instructions')}
                        description={t(
                            "Define the agent's role, tone, boundaries, and what it should prioritize when answering visitors.",
                        )}
                    />

                    <div className="grid gap-1.5">
                        <div className="flex items-center justify-between gap-3">
                            <Label htmlFor="prompt">{t('System prompt')}</Label>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {form.data.system_prompt.length}/2000
                            </span>
                        </div>
                        <Textarea
                            id="prompt"
                            rows={7}
                            className="min-h-48 resize-y bg-background/80 leading-6"
                            placeholder={t(
                                'You are a helpful sales assistant. Answer using the knowledge base, ask concise follow-up questions, and offer to collect contact details when a visitor is ready.',
                            )}
                            value={form.data.system_prompt}
                            aria-invalid={Boolean(form.errors.system_prompt)}
                            onChange={(e) =>
                                form.setData('system_prompt', e.target.value)
                            }
                        />
                        <FieldError>{form.errors.system_prompt}</FieldError>
                    </div>
                </section>

                <section className="grid gap-5 border-b p-4 sm:p-5 lg:grid-cols-[220px_1fr] lg:gap-8">
                    <SectionIntro
                        icon={<ShieldCheck className="size-4" />}
                        eyebrow={t('Retrieval')}
                        title={t('Retrieval confidence')}
                        description={t(
                            'Control how certain the agent must be before it answers from retrieved knowledge.',
                        )}
                    />

                    <div className="grid max-w-2xl gap-4">
                        <div className="rounded-lg border bg-background/70 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <Label htmlFor="conf">
                                        {t('Threshold')}
                                    </Label>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'Below this score, the agent will say it does not know instead of guessing.',
                                        )}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-semibold text-foreground tabular-nums">
                                        {confidencePercent}%
                                    </span>
                                    <Input
                                        id="conf"
                                        type="number"
                                        min={0}
                                        max={1}
                                        step={0.01}
                                        value={confidenceValue}
                                        onChange={(e) =>
                                            form.setData(
                                                'confidence_threshold',
                                                parseFloat(e.target.value),
                                            )
                                        }
                                        className="h-9 w-24 tabular-nums"
                                    />
                                </div>
                            </div>
                            <input
                                type="range"
                                min={0}
                                max={1}
                                step={0.01}
                                value={confidenceValue}
                                onChange={(e) =>
                                    form.setData(
                                        'confidence_threshold',
                                        parseFloat(e.target.value),
                                    )
                                }
                                className="mt-4 w-full accent-foreground"
                                aria-label={t('Retrieval confidence threshold')}
                            />
                        </div>
                        <p className="rounded-lg border bg-muted/25 px-3 py-2 text-xs leading-5 text-muted-foreground">
                            {t(
                                'Default 0.5 is tuned for Cloudflare bge-base. Raise toward 0.78 if you switch to OpenAI embeddings or need stricter answer confidence.',
                            )}
                        </p>
                    </div>
                </section>

                <div className="flex flex-col-reverse gap-2 bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-end sm:p-5">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="w-full sm:w-auto"
                    >
                        {mode === 'create'
                            ? t('Create agent')
                            : t('Save changes')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}
