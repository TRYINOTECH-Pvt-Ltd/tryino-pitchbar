import { Head, useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';

type ProviderKey = 'cloudflare' | 'openai' | 'openrouter' | 'qdrant';

type Props = {
    has_keys: Record<ProviderKey, boolean>;
    public_fields: Record<string, string>;
    global_byok_required: boolean;
};

export default function ByokKeysPage({
    has_keys,
    public_fields,
    global_byok_required,
}: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const form = useForm({
        cloudflare_account_id: public_fields.cloudflare_account_id ?? '',
        cloudflare_api_token: '',
        cloudflare_vectorize_index:
            public_fields.cloudflare_vectorize_index ?? '',
        cloudflare_chat_model: public_fields.cloudflare_chat_model ?? '',
        cloudflare_embed_model: public_fields.cloudflare_embed_model ?? '',
        openai_api_key: '',
        openai_chat_model: public_fields.openai_chat_model ?? '',
        openai_embed_model: public_fields.openai_embed_model ?? '',
        openrouter_api_key: '',
        openrouter_chat_model: public_fields.openrouter_chat_model ?? '',
        qdrant_url: public_fields.qdrant_url ?? '',
        qdrant_api_key: '',
        qdrant_collection: public_fields.qdrant_collection ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch('/settings/byok-keys', { preserveScroll: true });
    };

    const clear = async (provider: ProviderKey) => {
        const ok = await confirm({
            title: t('Clear keys?'),
            message: t('Clear all keys for this provider?'),
            confirmLabel: t('Clear'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        form.delete(`/settings/byok-keys/${provider}`, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('API keys')} />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {t('API keys (BYOK)')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {global_byok_required
                            ? t(
                                  'This deployment requires every workspace to supply its own AI provider keys. Pick whichever provider you want to use and drop the credentials below.',
                              )
                            : t(
                                  'BYOK is enabled for your workspace. Paste your own AI provider keys to take the chat off the operator’s shared infrastructure.',
                              )}
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                {t('Cloudflare Workers AI')}
                                {has_keys.cloudflare ? (
                                    <Badge variant="default">
                                        {t('configured')}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">
                                        {t('not set')}
                                    </Badge>
                                )}
                            </CardTitle>
                            <CardDescription>
                                {t(
                                    'Recommended. Same vendor for chat, embeddings, vector DB, browser rendering — single bill, lowest per-conversation cost.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="cf-account">
                                    {t('Account ID')}
                                </Label>
                                <Input
                                    id="cf-account"
                                    value={form.data.cloudflare_account_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'cloudflare_account_id',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="cf-token">
                                    {t(
                                        'API token (write to set, blank to keep)',
                                    )}
                                </Label>
                                <Input
                                    id="cf-token"
                                    type="password"
                                    placeholder="••••••••"
                                    value={form.data.cloudflare_api_token}
                                    onChange={(e) =>
                                        form.setData(
                                            'cloudflare_api_token',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="cf-vec">
                                    {t('Vectorize index')}
                                </Label>
                                <Input
                                    id="cf-vec"
                                    placeholder="pitchbar-chunks"
                                    value={form.data.cloudflare_vectorize_index}
                                    onChange={(e) =>
                                        form.setData(
                                            'cloudflare_vectorize_index',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="cf-chat">
                                    {t('Chat model')}
                                </Label>
                                <Input
                                    id="cf-chat"
                                    placeholder="@cf/meta/llama-3.3-70b-instruct-fp8-fast"
                                    value={form.data.cloudflare_chat_model}
                                    onChange={(e) =>
                                        form.setData(
                                            'cloudflare_chat_model',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </CardContent>
                        {has_keys.cloudflare && (
                            <CardContent className="pt-0">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => clear('cloudflare')}
                                >
                                    {t('Clear Cloudflare keys')}
                                </Button>
                            </CardContent>
                        )}
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                {t('OpenAI')}
                                {has_keys.openai ? (
                                    <Badge variant="default">
                                        {t('configured')}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">
                                        {t('not set')}
                                    </Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="oa-key">{t('API key')}</Label>
                                <Input
                                    id="oa-key"
                                    type="password"
                                    placeholder="••••••••"
                                    value={form.data.openai_api_key}
                                    onChange={(e) =>
                                        form.setData(
                                            'openai_api_key',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="oa-chat">
                                    {t('Chat model')}
                                </Label>
                                <Input
                                    id="oa-chat"
                                    placeholder="gpt-4o-mini"
                                    value={form.data.openai_chat_model}
                                    onChange={(e) =>
                                        form.setData(
                                            'openai_chat_model',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </CardContent>
                        {has_keys.openai && (
                            <CardContent className="pt-0">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => clear('openai')}
                                >
                                    {t('Clear OpenAI keys')}
                                </Button>
                            </CardContent>
                        )}
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                {t('Qdrant')}
                                {has_keys.qdrant ? (
                                    <Badge variant="default">
                                        {t('configured')}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">
                                        {t('not set')}
                                    </Badge>
                                )}
                            </CardTitle>
                            <CardDescription>
                                {t(
                                    'Self-hosted vector store. Use this when you don’t want chunks to leave your infrastructure.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="q-url">{t('Qdrant URL')}</Label>
                                <Input
                                    id="q-url"
                                    placeholder="https://qdrant.example.com"
                                    value={form.data.qdrant_url}
                                    onChange={(e) =>
                                        form.setData(
                                            'qdrant_url',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="q-key">{t('API key')}</Label>
                                <Input
                                    id="q-key"
                                    type="password"
                                    placeholder="••••••••"
                                    value={form.data.qdrant_api_key}
                                    onChange={(e) =>
                                        form.setData(
                                            'qdrant_api_key',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="q-col">{t('Collection')}</Label>
                                <Input
                                    id="q-col"
                                    placeholder="pitchbar_chunks"
                                    value={form.data.qdrant_collection}
                                    onChange={(e) =>
                                        form.setData(
                                            'qdrant_collection',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </CardContent>
                        {has_keys.qdrant && (
                            <CardContent className="pt-0">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => clear('qdrant')}
                                >
                                    {t('Clear Qdrant keys')}
                                </Button>
                            </CardContent>
                        )}
                    </Card>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? t('Saving…') : t('Save API keys')}
                    </Button>
                </form>
            </div>
        </>
    );
}
