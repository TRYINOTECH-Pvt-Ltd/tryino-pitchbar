import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update as updateSystemSettings } from '@/routes/settings/system';

export type PrivacyPolicyFormValue = {
    eyebrow: string;
    title: string;
    summary: string;
    effective_date: string;
    contact: {
        team_name: string;
        email: string;
        response_sla: string;
    };
    collection: {
        summary: string;
        items: string[];
    };
    usage: {
        summary: string;
        items: string[];
    };
    retention: {
        summary: string;
        items: string[];
    };
    rights: {
        summary: string;
        items: string[];
    };
    gdpr: {
        summary: string;
        request_email: string;
        request_instructions: string;
    };
};

type Props = {
    initial: PrivacyPolicyFormValue;
};

type PrivacyTabKey = 'overview' | 'processing' | 'rights';

function usePrivacyTabs(): Array<{
    key: PrivacyTabKey;
    label: string;
    description: string;
}> {
    const { t } = useT();

    return [
        {
            key: 'overview',
            label: t('Overview'),
            description: t(
                'Headline, effective date, and the privacy contact block.',
            ),
        },
        {
            key: 'processing',
            label: t('Collection & use'),
            description: t(
                'What you collect, how you use it, and what stays on record.',
            ),
        },
        {
            key: 'rights',
            label: t('Rights & GDPR'),
            description: t(
                'Visitor rights, deletion workflows, and request instructions.',
            ),
        },
    ];
}

function cloneContent(content: PrivacyPolicyFormValue): PrivacyPolicyFormValue {
    return JSON.parse(JSON.stringify(content)) as PrivacyPolicyFormValue;
}

function setNestedValue(
    source: PrivacyPolicyFormValue,
    path: Array<string | number>,
    value: unknown,
): PrivacyPolicyFormValue {
    const draft = cloneContent(source) as PrivacyPolicyFormValue &
        Record<string | number, unknown>;
    let cursor: Record<string | number, unknown> = draft;

    for (let index = 0; index < path.length - 1; index++) {
        cursor = cursor[path[index]] as Record<string | number, unknown>;
    }

    cursor[path[path.length - 1]] = value;

    return draft;
}

function stringListToText(values: string[]): string {
    return values.join('\n');
}

function textToStringList(value: string): string[] {
    return value
        .split('\n')
        .map((item) => item.trim())
        .filter((item) => item.length > 0);
}

export default function PrivacyPolicyEditor({ initial }: Props) {
    const { t } = useT();
    const PRIVACY_TABS = usePrivacyTabs();
    const [active, setActive] = useState<PrivacyTabKey>('overview');

    const form = useForm<{
        privacy_policy_content: PrivacyPolicyFormValue;
    }>({
        privacy_policy_content: initial,
    });

    const updateField = (path: Array<string | number>, value: unknown) => {
        form.setData(
            'privacy_policy_content',
            setNestedValue(form.data.privacy_policy_content, path, value),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.patch(updateSystemSettings.url('privacy'), {
            preserveScroll: true,
        });
    };

    return (
        <div className="space-y-4">
            <div className="grid gap-2 md:grid-cols-3">
                {PRIVACY_TABS.map((tab) => {
                    const isActive = active === tab.key;

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => setActive(tab.key)}
                            className={cn(
                                'rounded-xl border px-4 py-3 text-start transition',
                                isActive
                                    ? 'border-foreground bg-foreground/5'
                                    : 'border-border/80 hover:border-foreground/40',
                            )}
                        >
                            <p className="text-sm font-medium">{tab.label}</p>
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                {tab.description}
                            </p>
                        </button>
                    );
                })}
            </div>

            <form onSubmit={submit} className="space-y-4">
                {active === 'overview' && (
                    <Card className="border-border/80 p-5">
                        <div className="grid gap-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_eyebrow">
                                        {t('Eyebrow')}
                                    </Label>
                                    <Input
                                        id="privacy_eyebrow"
                                        value={
                                            form.data.privacy_policy_content
                                                .eyebrow
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['eyebrow'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_effective_date">
                                        {t('Effective date')}
                                    </Label>
                                    <Input
                                        id="privacy_effective_date"
                                        value={
                                            form.data.privacy_policy_content
                                                .effective_date
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['effective_date'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="privacy_title">
                                    {t('Page title')}
                                </Label>
                                <Input
                                    id="privacy_title"
                                    value={
                                        form.data.privacy_policy_content.title
                                    }
                                    onChange={(event) =>
                                        updateField(
                                            ['title'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="privacy_summary">
                                    {t('Summary')}
                                </Label>
                                <Textarea
                                    id="privacy_summary"
                                    rows={4}
                                    value={
                                        form.data.privacy_policy_content.summary
                                    }
                                    onChange={(event) =>
                                        updateField(
                                            ['summary'],
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="grid gap-4 rounded-xl border border-border/80 bg-muted/10 p-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {t('Privacy contact')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'This block is shown near the top of the public policy page so visitors know where to send formal requests.',
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="privacy_team_name">
                                            {t('Team name')}
                                        </Label>
                                        <Input
                                            id="privacy_team_name"
                                            value={
                                                form.data.privacy_policy_content
                                                    .contact.team_name
                                            }
                                            onChange={(event) =>
                                                updateField(
                                                    ['contact', 'team_name'],
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="privacy_contact_email">
                                            {t('Email')}
                                        </Label>
                                        <Input
                                            id="privacy_contact_email"
                                            type="email"
                                            value={
                                                form.data.privacy_policy_content
                                                    .contact.email
                                            }
                                            onChange={(event) =>
                                                updateField(
                                                    ['contact', 'email'],
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_response_sla">
                                        {t('Response promise')}
                                    </Label>
                                    <Textarea
                                        id="privacy_response_sla"
                                        rows={3}
                                        value={
                                            form.data.privacy_policy_content
                                                .contact.response_sla
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['contact', 'response_sla'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </div>
                    </Card>
                )}

                {active === 'processing' && (
                    <div className="space-y-4">
                        <Card className="border-border/80 p-5">
                            <div className="grid gap-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {t('What you collect')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'Each bullet becomes a line item on the public privacy page.',
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_collection_summary">
                                        {t('Summary')}
                                    </Label>
                                    <Textarea
                                        id="privacy_collection_summary"
                                        rows={3}
                                        value={
                                            form.data.privacy_policy_content
                                                .collection.summary
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['collection', 'summary'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_collection_items">
                                        {t('Bullet list')}
                                    </Label>
                                    <Textarea
                                        id="privacy_collection_items"
                                        rows={6}
                                        value={stringListToText(
                                            form.data.privacy_policy_content
                                                .collection.items,
                                        )}
                                        onChange={(event) =>
                                            updateField(
                                                ['collection', 'items'],
                                                textToStringList(
                                                    event.target.value,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </Card>

                        <Card className="border-border/80 p-5">
                            <div className="grid gap-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {t('How you use data')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'Use this section to explain AI replies, lead routing, analytics, and security processing.',
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_usage_summary">
                                        {t('Summary')}
                                    </Label>
                                    <Textarea
                                        id="privacy_usage_summary"
                                        rows={3}
                                        value={
                                            form.data.privacy_policy_content
                                                .usage.summary
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['usage', 'summary'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_usage_items">
                                        {t('Bullet list')}
                                    </Label>
                                    <Textarea
                                        id="privacy_usage_items"
                                        rows={6}
                                        value={stringListToText(
                                            form.data.privacy_policy_content
                                                .usage.items,
                                        )}
                                        onChange={(event) =>
                                            updateField(
                                                ['usage', 'items'],
                                                textToStringList(
                                                    event.target.value,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </Card>
                    </div>
                )}

                {active === 'rights' && (
                    <div className="space-y-4">
                        <Card className="border-border/80 p-5">
                            <div className="grid gap-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {t('Retention')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'Explain how long conversations, leads, logs, or backups stick around.',
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_retention_summary">
                                        {t('Summary')}
                                    </Label>
                                    <Textarea
                                        id="privacy_retention_summary"
                                        rows={3}
                                        value={
                                            form.data.privacy_policy_content
                                                .retention.summary
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['retention', 'summary'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_retention_items">
                                        {t('Bullet list')}
                                    </Label>
                                    <Textarea
                                        id="privacy_retention_items"
                                        rows={6}
                                        value={stringListToText(
                                            form.data.privacy_policy_content
                                                .retention.items,
                                        )}
                                        onChange={(event) =>
                                            updateField(
                                                ['retention', 'items'],
                                                textToStringList(
                                                    event.target.value,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </Card>

                        <Card className="border-border/80 p-5">
                            <div className="grid gap-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {t('Visitor rights')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'This becomes the “Your rights” section.',
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_rights_summary">
                                        {t('Summary')}
                                    </Label>
                                    <Textarea
                                        id="privacy_rights_summary"
                                        rows={3}
                                        value={
                                            form.data.privacy_policy_content
                                                .rights.summary
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['rights', 'summary'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_rights_items">
                                        {t('Bullet list')}
                                    </Label>
                                    <Textarea
                                        id="privacy_rights_items"
                                        rows={6}
                                        value={stringListToText(
                                            form.data.privacy_policy_content
                                                .rights.items,
                                        )}
                                        onChange={(event) =>
                                            updateField(
                                                ['rights', 'items'],
                                                textToStringList(
                                                    event.target.value,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </Card>

                        <Card className="border-border/80 p-5">
                            <div className="grid gap-4">
                                <div>
                                    <p className="text-sm font-medium">
                                        {t('GDPR request flow')}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        {t(
                                            'Clarify self-serve widget deletion, manual export requests, and where to send authenticated erasure requests.',
                                        )}
                                    </p>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_gdpr_summary">
                                        {t('Summary')}
                                    </Label>
                                    <Textarea
                                        id="privacy_gdpr_summary"
                                        rows={4}
                                        value={
                                            form.data.privacy_policy_content
                                                .gdpr.summary
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                ['gdpr', 'summary'],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="privacy_gdpr_email">
                                            {t('Request email')}
                                        </Label>
                                        <Input
                                            id="privacy_gdpr_email"
                                            type="email"
                                            value={
                                                form.data.privacy_policy_content
                                                    .gdpr.request_email
                                            }
                                            onChange={(event) =>
                                                updateField(
                                                    ['gdpr', 'request_email'],
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="privacy_gdpr_instructions">
                                        {t('Request instructions')}
                                    </Label>
                                    <Textarea
                                        id="privacy_gdpr_instructions"
                                        rows={4}
                                        value={
                                            form.data.privacy_policy_content
                                                .gdpr.request_instructions
                                        }
                                        onChange={(event) =>
                                            updateField(
                                                [
                                                    'gdpr',
                                                    'request_instructions',
                                                ],
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        </Card>
                    </div>
                )}

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {t('Save privacy policy')}
                    </Button>
                </div>
            </form>
        </div>
    );
}
