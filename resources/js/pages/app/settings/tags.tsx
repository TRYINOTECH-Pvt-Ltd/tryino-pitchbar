import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Tag as TagIcon, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { useConfirm } from '@/components/confirm-dialog-provider';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Tag = {
    id: string;
    label: string;
    color: string;
    created_at: string | null;
};

type Props = {
    tags: Tag[];
};

const PALETTE = [
    '#ef4444', // red
    '#f59e0b', // amber
    '#10b981', // emerald
    '#06b6d4', // cyan
    '#3b82f6', // blue
    '#8b5cf6', // violet
    '#ec4899', // pink
    '#64748b', // slate
];

export default function TagsSettings({ tags }: Props) {
    const { t } = useT();
    const confirm = useConfirm();
    const [editingId, setEditingId] = useState<string | null>(null);
    const [showCreate, setShowCreate] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Tags'), href: '/app/settings/tags' },
    ];

    const remove = async (id: string) => {
        const ok = await confirm({
            title: t('Delete tag?'),
            message: t(
                'Delete this tag? It will be removed from every conversation.',
            ),
            confirmLabel: t('Delete'),
            danger: true,
        });

        if (!ok) {
            return;
        }

        router.delete(`/app/settings/tags/${id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('Removed.')),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Tags')} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <TagIcon className="size-5" />
                            {t('Tags')}
                        </h1>
                        <p className="mt-1 max-w-prose text-sm text-muted-foreground">
                            {t(
                                'Categorize conversations so your team can filter and report on them later. Apply tags from the end-side panel inside any conversation thread.',
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
                        {t('New tag')}
                    </Button>
                </div>

                {showCreate && (
                    <TagForm
                        onDone={() => setShowCreate(false)}
                        method="post"
                    />
                )}

                {tags.length === 0 && !showCreate ? (
                    <Card className="p-8 text-center">
                        <TagIcon className="mx-auto size-10 text-muted-foreground/40" />
                        <p className="mt-3 text-sm font-medium">
                            {t('No tags yet.')}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t(
                                'Try "Billing", "Bug", "Refund", "Feature request".',
                            )}
                        </p>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-2">
                        {tags.map((tag) =>
                            editingId === tag.id ? (
                                <TagForm
                                    key={tag.id}
                                    tag={tag}
                                    onDone={() => setEditingId(null)}
                                    method="patch"
                                />
                            ) : (
                                <Card
                                    key={tag.id}
                                    className="flex items-center gap-3 p-3"
                                >
                                    <span
                                        className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold text-white"
                                        style={{ background: tag.color }}
                                    >
                                        <TagIcon className="size-3" />
                                        {tag.label}
                                    </span>
                                    <span className="font-mono text-[10px] text-muted-foreground uppercase">
                                        {tag.color}
                                    </span>
                                    <div className="ms-auto flex items-center gap-1.5">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditingId(tag.id)}
                                        >
                                            {t('Edit')}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => remove(tag.id)}
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

function TagForm({
    tag,
    onDone,
    method,
}: {
    tag?: Tag;
    onDone: () => void;
    method: 'post' | 'patch';
}) {
    const { t } = useT();
    const form = useForm({
        label: tag?.label ?? '',
        color: tag?.color ?? PALETTE[0]!,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const url = tag ? `/app/settings/tags/${tag.id}` : '/app/settings/tags';
        const opts = { preserveScroll: true, onSuccess: onDone };

        if (method === 'patch') {
            form.patch(url, opts);
        } else {
            form.post(url, opts);
        }
    };

    return (
        <Card className="p-4">
            <form onSubmit={submit} className="grid gap-3">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold">
                        {tag ? t('Edit tag') : t('New tag')}
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
                    <Label htmlFor={`label-${tag?.id ?? 'new'}`}>
                        {t('Label')}
                    </Label>
                    <Input
                        id={`label-${tag?.id ?? 'new'}`}
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        maxLength={60}
                        placeholder={t('Billing')}
                        required
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label>{t('Color')}</Label>
                    <div className="flex flex-wrap gap-1.5">
                        {PALETTE.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => form.setData('color', c)}
                                className={`size-7 rounded-full transition ${form.data.color === c ? 'ring-2 ring-foreground ring-offset-2' : 'hover:scale-110'}`}
                                style={{ background: c }}
                            />
                        ))}
                    </div>
                    <Input
                        type="text"
                        value={form.data.color}
                        onChange={(e) => form.setData('color', e.target.value)}
                        placeholder="#64748b"
                        maxLength={7}
                        className="mt-1 w-32 font-mono text-xs"
                    />
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
