import type { Method } from '@inertiajs/core';
import { Link, useForm } from '@inertiajs/react';
import { GripVertical, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { index as workflowsIndex } from '@/routes/workflows';

type Step = {
    type: 'message' | 'question' | 'escalate';
    text: string;
    var_name?: string | null;
};

export type WorkflowFormValues = {
    name: string;
    status: 'draft' | 'active' | 'disabled';
    agent_id: string | null;
    trigger_kind: 'on_keyword';
    keywords: string[];
    steps: Step[];
};

type AgentOption = { id: string; name: string };

type Props = {
    initial: WorkflowFormValues;
    method: Extract<Method, 'post' | 'patch'>;
    action: string;
    submitLabel: string;
    agents: AgentOption[];
};

export function WorkflowForm({
    initial,
    method,
    action,
    submitLabel,
    agents,
}: Props) {
    const { t } = useT();

    const stepTypeHelp: Record<Step['type'], string> = {
        message: t('Send a chat bubble — supports {{var}} interpolation.'),
        question: t(
            'Send a bubble + pause for the visitor reply; saved into var_name.',
        ),
        escalate: t(
            'Send a goodbye line + flag the conversation for human takeover.',
        ),
    };

    const form = useForm<WorkflowFormValues>(initial);

    // The keyword input is held as RAW text, not re-derived from the
    // parsed array. Binding `value={keywords.join(', ')}` + filtering
    // empties on every keystroke ate the comma the instant you typed it
    // (the trailing empty segment was dropped, so the controlled value
    // snapped back), making it impossible to enter a second keyword.
    // Keep the raw string for display; parse to the array only for the
    // submitted form data.
    const [keywordsRaw, setKeywordsRaw] = useState(initial.keywords.join(', '));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(method, action, { preserveScroll: true });
    };

    const updateStep = (index: number, patch: Partial<Step>) => {
        const next = [...form.data.steps];
        next[index] = { ...next[index], ...patch };
        form.setData('steps', next);
    };

    const removeStep = (index: number) => {
        form.setData(
            'steps',
            form.data.steps.filter((_, i) => i !== index),
        );
    };

    const addStep = (type: Step['type']) => {
        form.setData('steps', [
            ...form.data.steps,
            {
                type,
                text:
                    type === 'message'
                        ? ''
                        : type === 'question'
                          ? ''
                          : "I'll connect you with a human now.",
                var_name: type === 'question' ? 'visitor_answer' : null,
            },
        ]);
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Card className="p-4">
                <h2 className="font-semibold">{t('Basics')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        'Workflow runs before the LLM on every visitor turn. Status must be Active to actually fire.',
                    )}
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="wf-name">{t('Name')}</Label>
                        <Input
                            id="wf-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder={t('Pricing FAQ flow')}
                            required
                        />
                        {form.errors.name && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="wf-status">{t('Status')}</Label>
                        <select
                            id="wf-status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData(
                                    'status',
                                    e.target
                                        .value as WorkflowFormValues['status'],
                                )
                            }
                            className="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="draft">
                                {t('Draft (not running)')}
                            </option>
                            <option value="active">{t('Active')}</option>
                            <option value="disabled">{t('Disabled')}</option>
                        </select>
                    </div>

                    <div className="grid gap-1.5 md:col-span-2">
                        <Label htmlFor="wf-agent">{t('Scoped to agent')}</Label>
                        <select
                            id="wf-agent"
                            value={form.data.agent_id ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'agent_id',
                                    e.target.value === ''
                                        ? null
                                        : e.target.value,
                                )
                            }
                            className="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">
                                {t('Workspace-wide (every agent)')}
                            </option>
                            {agents.map((agent) => (
                                <option key={agent.id} value={agent.id}>
                                    {agent.name}
                                </option>
                            ))}
                        </select>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                "Leave on workspace-wide unless this flow only makes sense for one agent (e.g. a Shopify product Q&A shouldn't fire on a docs agent).",
                            )}
                        </p>
                    </div>
                </div>
            </Card>

            <Card className="p-4">
                <h2 className="font-semibold">{t('Trigger — keywords')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        "Comma-separated. Match is case-insensitive substring on the visitor's first message. The first matching workflow fires.",
                    )}
                </p>

                <Input
                    id="wf-keywords"
                    value={keywordsRaw}
                    onChange={(e) => {
                        setKeywordsRaw(e.target.value);
                        form.setData(
                            'keywords',
                            e.target.value
                                .split(',')
                                .map((k) => k.trim())
                                .filter(Boolean),
                        );
                    }}
                    placeholder={t('price, pricing, cost, plan')}
                />
                {form.errors.keywords && (
                    <p className="mt-1 text-xs text-destructive">
                        {form.errors.keywords}
                    </p>
                )}
            </Card>

            <Card className="p-4">
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="font-semibold">{t('Steps')}</h2>
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => addStep('message')}
                        >
                            {t('+ Message')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => addStep('question')}
                        >
                            {t('+ Question')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => addStep('escalate')}
                        >
                            {t('+ Escalate')}
                        </Button>
                    </div>
                </div>

                {form.data.steps.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Add at least one step. Steps run top-to-bottom; a question pauses the flow until the visitor replies.',
                        )}
                    </p>
                ) : (
                    <div className="grid gap-3">
                        {form.data.steps.map((step, index) => (
                            <div key={index} className="rounded-lg border p-3">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        <GripVertical className="size-3.5" />
                                        {t('Step :index ·', {
                                            index: index + 1,
                                        })}{' '}
                                        <span className="text-foreground">
                                            {step.type}
                                        </span>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeStep(index)}
                                    >
                                        <Trash2 className="size-4 text-destructive" />
                                    </Button>
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {stepTypeHelp[step.type]}
                                </p>

                                <div className="mt-3 grid gap-2">
                                    <Label
                                        htmlFor={`wf-step-${index}-text`}
                                        className="text-xs"
                                    >
                                        {t('Bubble text')}
                                    </Label>
                                    <textarea
                                        id={`wf-step-${index}-text`}
                                        value={step.text}
                                        onChange={(e) =>
                                            updateStep(index, {
                                                text: e.target.value,
                                            })
                                        }
                                        rows={2}
                                        className="rounded-md border bg-background p-2 text-sm"
                                    />
                                    {step.type === 'question' && (
                                        <>
                                            <Label
                                                htmlFor={`wf-step-${index}-var`}
                                                className="text-xs"
                                            >
                                                {t(
                                                    'Save the answer to var_name',
                                                )}
                                            </Label>
                                            <Input
                                                id={`wf-step-${index}-var`}
                                                value={step.var_name ?? ''}
                                                onChange={(e) =>
                                                    updateStep(index, {
                                                        var_name:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="visitor_answer"
                                            />
                                        </>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
                {typeof form.errors.steps === 'string' && (
                    <p className="mt-2 text-xs text-destructive">
                        {form.errors.steps}
                    </p>
                )}
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild type="button" variant="ghost">
                    <Link href={workflowsIndex()}>{t('Cancel')}</Link>
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? t('Saving…') : submitLabel}
                </Button>
            </div>
        </form>
    );
}
