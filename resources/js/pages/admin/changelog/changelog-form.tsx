import type { Method } from '@inertiajs/core';
import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { index as adminChangelogIndex } from '@/routes/admin/changelog';

export type ChangelogFormValues = {
    version: string;
    released_at: string;
    status: 'draft' | 'published' | 'archived';
    title: string;
    body: string;
};

type Props = {
    initial: ChangelogFormValues;
    method: Extract<Method, 'post' | 'patch'>;
    action: string;
    submitLabel: string;
    /** When true, the version field is locked (published entries). */
    versionLocked?: boolean;
};

export function ChangelogForm({
    initial,
    method,
    action,
    submitLabel,
    versionLocked = false,
}: Props) {
    const { t } = useT();
    const form = useForm<ChangelogFormValues>(initial);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(method, action, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Card className="p-4">
                <h2 className="font-semibold">{t('Headline')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t(
                        'The version + title shown on the public /changelog page. Use semver (v1.4.0). Once published, the version is immutable so old buyer links keep resolving.',
                    )}
                </p>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="cl-version">{t('Version')}</Label>
                        <Input
                            id="cl-version"
                            value={form.data.version}
                            onChange={(e) =>
                                form.setData('version', e.target.value)
                            }
                            placeholder="v1.4.0"
                            disabled={versionLocked}
                            required
                        />
                        {versionLocked && (
                            <p className="text-[11px] text-muted-foreground">
                                {t(
                                    'Locked — this entry is published. Archive it first if you need a new version.',
                                )}
                            </p>
                        )}
                        {form.errors.version && (
                            <p className="text-xs text-destructive">
                                {form.errors.version}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="cl-released-at">
                            {t('Released at')}
                        </Label>
                        <Input
                            id="cl-released-at"
                            type="date"
                            value={form.data.released_at}
                            onChange={(e) =>
                                form.setData('released_at', e.target.value)
                            }
                        />
                        <p className="text-[11px] text-muted-foreground">
                            {t('Leave blank to use today on publish.')}
                        </p>
                    </div>

                    <div className="grid gap-1.5 md:col-span-2">
                        <Label htmlFor="cl-title">{t('Title')}</Label>
                        <Input
                            id="cl-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder={t(
                                'Workflow visual editor + Phase 2 step types',
                            )}
                            required
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="cl-status">{t('Status')}</Label>
                        <select
                            id="cl-status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData(
                                    'status',
                                    e.target
                                        .value as ChangelogFormValues['status'],
                                )
                            }
                            className="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="draft">{t('Draft (hidden)')}</option>
                            <option value="published">{t('Published')}</option>
                            <option value="archived">{t('Archived')}</option>
                        </select>
                    </div>
                </div>
            </Card>

            <Card className="p-4">
                <h2 className="font-semibold">{t('Body (markdown)')}</h2>
                <p className="mb-4 text-xs text-muted-foreground">
                    {t('Follow the')}{' '}
                    <a
                        className="underline"
                        href="https://keepachangelog.com/"
                        target="_blank"
                        rel="noreferrer"
                    >
                        {t('Keep a Changelog')}
                    </a>{' '}
                    {t('convention — sections under')} <code>## Added</code> /{' '}
                    <code>## Changed</code> / <code>## Fixed</code> /{' '}
                    <code>## Removed</code> / <code>## Security</code>.
                </p>

                <textarea
                    id="cl-body"
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    rows={16}
                    className="rounded-md border bg-background p-2 font-mono text-sm"
                    required
                    placeholder={[
                        '## Added',
                        '- React Flow visual editor for workflows.',
                        '',
                        '## Changed',
                        '- Workflow trigger now supports any/all/exact match modes.',
                        '',
                        '## Fixed',
                        '- Mobile homepage hero overflow on viewports under 480px.',
                    ].join('\n')}
                />
                {form.errors.body && (
                    <p className="mt-1 text-xs text-destructive">
                        {form.errors.body}
                    </p>
                )}
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild type="button" variant="ghost">
                    <Link href={adminChangelogIndex()}>{t('Cancel')}</Link>
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? t('Saving…') : submitLabel}
                </Button>
            </div>
        </form>
    );
}
