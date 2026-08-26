import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ScrollText } from 'lucide-react';
import { useState } from 'react';
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
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type AuditRow = {
    id: number;
    action: string;
    entity_type: string | null;
    entity_id: string | null;
    user: { id: number; name: string; email: string } | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    ip: string | null;
    ua: string | null;
    created_at: string | null;
};

type Pagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type Filters = {
    action: string;
    entity_type: string;
    user_email: string;
    from: string;
    to: string;
};

type Props = {
    rows: AuditRow[];
    pagination: Pagination;
    filters: Filters;
    entityTypes: string[];
};

const ALL_ENTITY_TYPES = '__all__';

function fmt(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) {
        return iso;
    }

    return d.toLocaleString();
}

export default function AuditIndexPage({
    rows,
    pagination,
    filters,
    entityTypes,
}: Props) {
    const { t } = useT();
    const [draft, setDraft] = useState<Filters>(filters);
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('Audit log'), href: '/app/audit' },
    ];

    const apply = (overrides: Partial<Filters> = {}, page = 1) => {
        const merged = { ...draft, ...overrides };
        const params: Record<string, string | number> = { page };
        (Object.keys(merged) as (keyof Filters)[]).forEach((key) => {
            const v = merged[key];

            if (typeof v === 'string' && v.trim() !== '') {
                params[key] = v;
            }
        });
        router.get('/app/audit', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const reset = () => {
        setDraft({
            action: '',
            entity_type: '',
            user_email: '',
            from: '',
            to: '',
        });
        router.get('/app/audit', {}, { preserveScroll: true });
    };

    const toggle = (id: number) => {
        setExpanded((prev) => ({ ...prev, [id]: !prev[id] }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Audit log')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <ScrollText className="h-6 w-6 text-muted-foreground" />
                    <div>
                        <h1 className="text-xl font-semibold">
                            {t('Audit log')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Workspace activity trail — member changes, integrations, GDPR requests, webhook failures.',
                            )}
                        </p>
                    </div>
                </div>

                <Card className="p-4">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="space-y-1">
                            <Label htmlFor="audit-action">{t('Action')}</Label>
                            <Input
                                id="audit-action"
                                placeholder={t('e.g. dsr.exported')}
                                value={draft.action}
                                onChange={(e) =>
                                    setDraft((d) => ({
                                        ...d,
                                        action: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="audit-entity">
                                {t('Entity type')}
                            </Label>
                            <Select
                                value={
                                    draft.entity_type === ''
                                        ? ALL_ENTITY_TYPES
                                        : draft.entity_type
                                }
                                onValueChange={(v) =>
                                    setDraft((d) => ({
                                        ...d,
                                        entity_type:
                                            v === ALL_ENTITY_TYPES ? '' : v,
                                    }))
                                }
                            >
                                <SelectTrigger id="audit-entity">
                                    <SelectValue placeholder={t('Any')} />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_ENTITY_TYPES}>
                                        {t('Any')}
                                    </SelectItem>
                                    {entityTypes.map((et) => (
                                        <SelectItem key={et} value={et}>
                                            {et}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="audit-user">
                                {t('Actor email')}
                            </Label>
                            <Input
                                id="audit-user"
                                type="email"
                                placeholder="user@example.com"
                                value={draft.user_email}
                                onChange={(e) =>
                                    setDraft((d) => ({
                                        ...d,
                                        user_email: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="audit-from">{t('From')}</Label>
                            <Input
                                id="audit-from"
                                type="date"
                                value={draft.from}
                                onChange={(e) =>
                                    setDraft((d) => ({
                                        ...d,
                                        from: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="audit-to">{t('To')}</Label>
                            <Input
                                id="audit-to"
                                type="date"
                                value={draft.to}
                                onChange={(e) =>
                                    setDraft((d) => ({
                                        ...d,
                                        to: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                    <div className="mt-4 flex justify-end gap-2">
                        <Button variant="ghost" onClick={reset}>
                            {t('Reset')}
                        </Button>
                        <Button onClick={() => apply()}>{t('Apply')}</Button>
                    </div>
                </Card>

                <Card className="overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="p-3">{t('When')}</th>
                                    <th className="p-3">{t('Actor')}</th>
                                    <th className="p-3">{t('Action')}</th>
                                    <th className="p-3">{t('Entity')}</th>
                                    <th className="p-3">{t('IP')}</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="p-8 text-center text-muted-foreground"
                                        >
                                            {t(
                                                'No audit entries match the current filters.',
                                            )}
                                        </td>
                                    </tr>
                                )}
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-t border-border align-top"
                                    >
                                        <td className="p-3 whitespace-nowrap">
                                            {fmt(row.created_at)}
                                        </td>
                                        <td className="p-3">
                                            {row.user ? (
                                                <div>
                                                    <div className="font-medium">
                                                        {row.user.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {row.user.email}
                                                    </div>
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {t('System')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                {row.action}
                                            </code>
                                        </td>
                                        <td className="p-3 text-xs">
                                            {row.entity_type ? (
                                                <div>
                                                    <div>{row.entity_type}</div>
                                                    {row.entity_id && (
                                                        <div className="text-muted-foreground">
                                                            {row.entity_id}
                                                        </div>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-3 text-xs text-muted-foreground">
                                            {row.ip ?? '—'}
                                        </td>
                                        <td className="p-3 text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => toggle(row.id)}
                                            >
                                                {expanded[row.id]
                                                    ? t('Hide')
                                                    : t('Details')}
                                            </Button>
                                            {expanded[row.id] && (
                                                <div className="mt-2 max-w-xl text-left">
                                                    <details
                                                        open
                                                        className="rounded border border-border bg-muted/30 p-2 text-xs"
                                                    >
                                                        <summary className="cursor-pointer font-medium">
                                                            {t(
                                                                'Before / After',
                                                            )}
                                                        </summary>
                                                        <pre className="mt-2 max-h-72 overflow-auto break-all whitespace-pre-wrap">
                                                            {JSON.stringify(
                                                                {
                                                                    before: row.before,
                                                                    after: row.after,
                                                                    ua: row.ua,
                                                                },
                                                                null,
                                                                2,
                                                            )}
                                                        </pre>
                                                    </details>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between border-t border-border p-3 text-sm">
                        <div className="text-muted-foreground">
                            {t('Showing :count of :total entries', {
                                count: rows.length,
                                total: pagination.total,
                            })}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={pagination.current_page <= 1}
                                onClick={() =>
                                    apply({}, pagination.current_page - 1)
                                }
                            >
                                <ChevronLeft className="h-4 w-4" />
                                {t('Prev')}
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                {pagination.current_page} /{' '}
                                {pagination.last_page}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={
                                    pagination.current_page >=
                                    pagination.last_page
                                }
                                onClick={() =>
                                    apply({}, pagination.current_page + 1)
                                }
                            >
                                {t('Next')}
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </Card>
            </div>
        </AppLayout>
    );
}
