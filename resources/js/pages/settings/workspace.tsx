import { Head, useForm } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';

type Props = {
    workspace: {
        id: string;
        name: string;
        slug: string | null;
        public_kb_url: string | null;
    };
};

function CopyableField({ label, value }: { label: string; value: string }) {
    const { t } = useT();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // best-effort; clipboard API can be blocked in iframes / non-https
        }
    };

    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            <div className="flex items-stretch gap-2">
                <Input value={value} readOnly className="font-mono text-xs" />
                <Button
                    type="button"
                    variant="outline"
                    onClick={copy}
                    aria-label={t('Copy')}
                    className="shrink-0"
                >
                    {copied ? (
                        <>
                            <Check className="size-4" />
                            <span className="ms-1.5">{t('Copied')}</span>
                        </>
                    ) : (
                        <>
                            <Copy className="size-4" />
                            <span className="ms-1.5">{t('Copy')}</span>
                        </>
                    )}
                </Button>
            </div>
        </div>
    );
}

export default function WorkspaceSettings({ workspace }: Props) {
    const { t } = useT();

    const form = useForm({ name: workspace.name });

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        form.patch('/settings/workspace', { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('Workspace settings')} />

            <h1 className="sr-only">{t('Workspace settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Workspace')}
                    description={t(
                        'Rename the workspace your team sees in the sidebar and on outgoing emails.',
                    )}
                />

                <Card className="border-border/80 p-5">
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="name">{t('Workspace name')}</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                required
                                placeholder={t('Acme, Inc.')}
                                autoComplete="organization"
                                data-test="workspace-name"
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="flex items-center justify-end gap-4 border-t pt-4">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                data-test="update-workspace-button"
                            >
                                {t('Save')}
                            </Button>
                        </div>
                    </form>
                </Card>

                {(workspace.slug || workspace.public_kb_url) && (
                    <Card className="border-border/80 p-5">
                        <div className="grid gap-4">
                            <div>
                                <h2 className="text-base font-semibold">
                                    {t('Workspace identity')}
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {t(
                                        'These identifiers belong to your workspace. The slug is used in public URLs (knowledge base, share links). The URL below is your public knowledge-base page — share it with customers who want to read your published answers.',
                                    )}
                                </p>
                            </div>

                            {workspace.slug && (
                                <CopyableField
                                    label={t('Workspace slug')}
                                    value={workspace.slug}
                                />
                            )}

                            {workspace.public_kb_url && (
                                <CopyableField
                                    label={t('Public knowledge-base URL')}
                                    value={workspace.public_kb_url}
                                />
                            )}
                        </div>
                    </Card>
                )}
            </div>
        </>
    );
}

WorkspaceSettings.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: '/settings/workspace',
        },
    ],
};
