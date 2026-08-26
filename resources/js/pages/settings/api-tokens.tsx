import { Form, Head, router, usePage } from '@inertiajs/react';
import { Copy, Eraser, Trash2 } from 'lucide-react';
import { useState } from 'react';
import WorkspaceApiTokenController from '@/actions/App/Http/Controllers/Settings/WorkspaceApiTokenController';
import { useConfirm } from '@/components/confirm-dialog-provider';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { index as apiTokensIndex } from '@/routes/settings/api-tokens';

type ApiToken = {
    id: string;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    revoked_at: string | null;
    created_at: string;
    created_by_user_id: string | null;
    created_by?: { id: string; name: string; email: string } | null;
};

type Props = {
    tokens: ApiToken[];
    abilities: string[];
    new_token: string | null;
};

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleString();
}

export default function ApiTokensPage({ tokens, abilities, new_token }: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (new_token === null) {
            return;
        }

        await navigator.clipboard.writeText(new_token);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title={t('API tokens')} />

            <h1 className="sr-only">{t('API tokens')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('API tokens')}
                    description={t(
                        'Workspace tokens authenticate first-party integrations like the WordPress plugin. Tokens carry only the abilities you grant them.',
                    )}
                />

                {new_token !== null && (
                    <Card className="border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                        <p className="text-sm font-medium text-emerald-900 dark:text-emerald-100">
                            {t('Copy this token now')}
                        </p>
                        <p className="mt-1 text-xs text-emerald-800/80 dark:text-emerald-100/70">
                            {t(
                                'For your security we only show the plaintext once. If you lose it, revoke and create a new one.',
                            )}
                        </p>
                        <div className="mt-3 flex items-center gap-2">
                            <code className="flex-1 truncate rounded-md bg-background px-3 py-2 font-mono text-sm">
                                {new_token}
                            </code>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={copy}
                            >
                                <Copy className="mr-1 h-3.5 w-3.5" />
                                {copied ? t('Copied') : t('Copy')}
                            </Button>
                        </div>
                    </Card>
                )}

                {flash?.success && new_token === null && (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100">
                        {flash.success}
                    </div>
                )}

                <Card className="border-border/80 p-5">
                    <Form
                        {...WorkspaceApiTokenController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">{t('Name')}</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        placeholder={t(
                                            'e.g. WordPress: shop.example.com',
                                        )}
                                        maxLength={120}
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>{t('Abilities')}</Label>
                                    {abilities.map((ability) => (
                                        <div
                                            key={ability}
                                            className="flex items-start gap-3"
                                        >
                                            <Checkbox
                                                id={`ability-${ability}`}
                                                name="abilities[]"
                                                value={ability}
                                                defaultChecked
                                            />
                                            <Label
                                                htmlFor={`ability-${ability}`}
                                                className="font-mono text-sm font-normal"
                                            >
                                                {ability}
                                            </Label>
                                        </div>
                                    ))}
                                    <InputError
                                        message={
                                            (errors as Record<string, string>)[
                                                'abilities'
                                            ]
                                        }
                                    />
                                </div>

                                <div className="flex items-center justify-end gap-4 border-t pt-4">
                                    <Button
                                        disabled={processing}
                                        data-test="create-api-token-button"
                                    >
                                        {t('Create token')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Card>

                <div className="space-y-3">
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-muted-foreground">
                            {t('Existing tokens')}
                        </h2>
                        {tokens.some((tk) => tk.revoked_at !== null) && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={async () => {
                                    const ok = await confirm({
                                        title: t('Purge revoked tokens?'),
                                        message: t(
                                            'Permanently delete every revoked token in this workspace. Active tokens are kept.',
                                        ),
                                        confirmLabel: t('Purge'),
                                        danger: true,
                                    });

                                    if (!ok) {
                                        return;
                                    }

                                    router.post(
                                        WorkspaceApiTokenController.purgeRevoked.url(),
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <Eraser className="mr-1 h-3.5 w-3.5" />
                                {t('Purge revoked')}
                            </Button>
                        )}
                    </div>

                    {tokens.length === 0 ? (
                        <Card className="border-dashed p-6 text-center text-sm text-muted-foreground">
                            {t('No tokens yet.')}
                        </Card>
                    ) : (
                        <div className="grid gap-2">
                            {tokens.map((token) => (
                                <Card
                                    key={token.id}
                                    className="flex items-center gap-4 border-border/70 p-4"
                                >
                                    <div className="flex-1 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <p className="font-medium">
                                                {token.name}
                                            </p>
                                            {token.revoked_at !== null && (
                                                <Badge variant="destructive">
                                                    {t('Revoked')}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {token.abilities.map((ability) => (
                                                <Badge
                                                    key={ability}
                                                    variant="secondary"
                                                    className="font-mono text-[10px]"
                                                >
                                                    {ability}
                                                </Badge>
                                            ))}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {t('Created')}{' '}
                                            {formatDate(token.created_at)}
                                            {' · '}
                                            {t('Last used')}{' '}
                                            {formatDate(token.last_used_at)}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-1">
                                        {token.revoked_at === null && (
                                            <Form
                                                {...WorkspaceApiTokenController.destroy.form(
                                                    token.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                        onClick={async (e) => {
                                                            e.preventDefault();
                                                            const form =
                                                                e.currentTarget.closest(
                                                                    'form',
                                                                );
                                                            const ok =
                                                                await confirm({
                                                                    title: t(
                                                                        'Revoke token?',
                                                                    ),
                                                                    message: t(
                                                                        'Revoke this token? Integrations using it will stop working immediately.',
                                                                    ),
                                                                    confirmLabel:
                                                                        t(
                                                                            'Revoke',
                                                                        ),
                                                                    danger: true,
                                                                });

                                                            if (!ok) {
                                                                return;
                                                            }

                                                            form?.requestSubmit();
                                                        }}
                                                    >
                                                        <Trash2 className="mr-1 h-3.5 w-3.5" />
                                                        {t('Revoke')}
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={async () => {
                                                const ok = await confirm({
                                                    title: t('Forget token?'),
                                                    message:
                                                        token.revoked_at ===
                                                        null
                                                            ? t(
                                                                  'This will revoke and then permanently delete the token. Anything using it stops working immediately. Continue?',
                                                              )
                                                            : t(
                                                                  'Permanently delete this revoked token? The row disappears from the list.',
                                                              ),
                                                    confirmLabel: t('Forget'),
                                                    danger: true,
                                                });

                                                if (!ok) {
                                                    return;
                                                }

                                                router.delete(
                                                    WorkspaceApiTokenController.forceDestroy.url(
                                                        token.id,
                                                    ),
                                                    { preserveScroll: true },
                                                );
                                            }}
                                        >
                                            <Eraser className="mr-1 h-3.5 w-3.5" />
                                            {t('Forget')}
                                        </Button>
                                    </div>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

ApiTokensPage.layout = {
    breadcrumbs: [
        {
            title: 'API tokens',
            href: apiTokensIndex(),
        },
    ],
};
