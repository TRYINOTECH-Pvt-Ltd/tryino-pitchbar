import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Sparkles, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Reply = {
    id: string;
    label: string;
    content: string;
    position: number;
    created_at: string | null;
};

type Props = {
    replies: Reply[];
};

export default function CannedRepliesSettings({ replies }: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const [editingId, setEditingId] = useState<string | null>(null);
    const [showCreate, setShowCreate] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Canned replies'), href: '/app/settings/canned-replies' },
    ];

    const move = (id: string, direction: 'up' | 'down') => {
        const idx = replies.findIndex((r) => r.id === id);

        if (idx === -1) {
            return;
        }

        const swap = direction === 'up' ? idx - 1 : idx + 1;

        if (swap < 0 || swap >= replies.length) {
            return;
        }

        const next = [...replies];
        [next[idx], next[swap]] = [next[swap], next[idx]];

        router.patch(
            '/app/settings/canned-replies/reorder',
            { ordered_ids: next.map((r) => r.id) },
            { preserveScroll: true },
        );
    };

    const remove = async (id: string) => {
        const ok = await confirm({
            title: t('Delete canned reply?'),
            message: t('Delete this canned reply? This cannot be undone.'),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(`/app/settings/canned-replies/${id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('Removed.')),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Canned replies')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <Sparkles className="size-5" />
                            {t('Canned replies')}
                        </h1>
                        <p className="mt-1 max-w-prose text-sm text-muted-foreground">
                            {t(
                                'Save the replies you find yourself typing over and over. Operators can drop them into a live conversation in two clicks.',
                            )}
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => setShowCreate(true)}
                        disabled={showCreate}
                    >
                        <Plus className="me-1.5 size-3.5" />
                        {t('New canned reply')}
                    </Button>
                </div>

                {showCreate && (
                    <CreateForm onDone={() => setShowCreate(false)} />
                )}

                {replies.length === 0 && !showCreate ? (
                    <Card className="p-8 text-center">
                        <Sparkles className="mx-auto size-10 text-muted-foreground/40" />
                        <p className="mt-3 text-sm font-medium">
                            {t('No canned replies yet.')}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t(
                                'Add one to make your operators 10× faster on common questions.',
                            )}
                        </p>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-2">
                        {replies.map((r, i) =>
                            editingId === r.id ? (
                                <EditForm
                                    key={r.id}
                                    reply={r}
                                    onDone={() => setEditingId(null)}
                                />
                            ) : (
                                <Card
                                    key={r.id}
                                    className="flex flex-wrap items-start gap-3 p-3"
                                >
                                    <div className="flex flex-col gap-1">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            disabled={i === 0}
                                            onClick={() => move(r.id, 'up')}
                                            className="h-6 w-6 p-0"
                                        >
                                            <ArrowUp className="size-3" />
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            disabled={i === replies.length - 1}
                                            onClick={() => move(r.id, 'down')}
                                            className="h-6 w-6 p-0"
                                        >
                                            <ArrowDown className="size-3" />
                                        </Button>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold">
                                            {r.label}
                                        </p>
                                        <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                            {r.content}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1.5">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditingId(r.id)}
                                        >
                                            {t('Edit')}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => remove(r.id)}
                                            className="text-red-600 hover:text-red-700"
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </div>
                                </Card>
                            ),
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function CreateForm({ onDone }: { onDone: () => void }) {
    const { t } = useT();
    const form = useForm({ label: '', content: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/app/settings/canned-replies', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };

    return (
        <Card className="p-4">
            <form onSubmit={submit} className="grid gap-3">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold">
                        {t('New canned reply')}
                    </h2>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={onDone}
                        className="h-7 w-7 p-0"
                    >
                        <X className="size-3.5" />
                    </Button>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="label">{t('Label')}</Label>
                    <Input
                        id="label"
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        placeholder={t('Reset password')}
                        maxLength={80}
                        required
                    />
                    {form.errors.label && (
                        <p className="text-xs text-red-600">
                            {form.errors.label}
                        </p>
                    )}
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="content">{t('Reply text')}</Label>
                    <Textarea
                        id="content"
                        rows={4}
                        value={form.data.content}
                        onChange={(e) =>
                            form.setData('content', e.target.value)
                        }
                        placeholder={t(
                            'Click "Forgot password" on the sign-in page and follow the email link.',
                        )}
                        required
                    />
                    {form.errors.content && (
                        <p className="text-xs text-red-600">
                            {form.errors.content}
                        </p>
                    )}
                </div>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('Save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function EditForm({ reply, onDone }: { reply: Reply; onDone: () => void }) {
    const { t } = useT();
    const form = useForm({ label: reply.label, content: reply.content });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(`/app/settings/canned-replies/${reply.id}`, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <Card className="p-4">
            <form onSubmit={submit} className="grid gap-3">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold">
                        {t('Edit canned reply')}
                    </h2>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={onDone}
                        className="h-7 w-7 p-0"
                    >
                        <X className="size-3.5" />
                    </Button>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`edit-label-${reply.id}`}>
                        {t('Label')}
                    </Label>
                    <Input
                        id={`edit-label-${reply.id}`}
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        maxLength={80}
                        required
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`edit-content-${reply.id}`}>
                        {t('Reply text')}
                    </Label>
                    <Textarea
                        id={`edit-content-${reply.id}`}
                        rows={4}
                        value={form.data.content}
                        onChange={(e) =>
                            form.setData('content', e.target.value)
                        }
                        required
                    />
                </div>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onDone}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('Save changes')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}
