import { AlertTriangle, RefreshCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Shared loading + error primitives. Pair with the existing EmptyState so
 * every async surface — tables, lists, dashboards — has a designed empty,
 * loading, and error state instead of a blank screen.
 */

export function TableSkeleton({
    rows = 5,
    columns = 4,
    className,
}: {
    rows?: number;
    columns?: number;
    className?: string;
}) {
    return (
        <Card className={cn('overflow-hidden p-0', className)}>
            <div className="border-b bg-muted/30 px-4 py-3">
                <div className="flex gap-6">
                    {Array.from({ length: columns }).map((_, i) => (
                        <Skeleton key={`h-${i}`} className="h-3 flex-1" />
                    ))}
                </div>
            </div>
            <div className="divide-y">
                {Array.from({ length: rows }).map((_, r) => (
                    <div key={`r-${r}`} className="flex gap-6 px-4 py-4">
                        {Array.from({ length: columns }).map((_, c) => (
                            <Skeleton
                                key={`c-${r}-${c}`}
                                className={cn(
                                    'h-3.5 flex-1',
                                    c === 0 && 'h-3.5 max-w-[40%]',
                                )}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </Card>
    );
}

export function CardListSkeleton({
    count = 6,
    className,
}: {
    count?: number;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'grid gap-4 sm:grid-cols-2 lg:grid-cols-3',
                className,
            )}
        >
            {Array.from({ length: count }).map((_, i) => (
                <Card key={i} className="space-y-3 p-5">
                    <div className="flex items-center gap-3">
                        <Skeleton className="size-9 rounded-md" />
                        <Skeleton className="h-3.5 flex-1" />
                    </div>
                    <Skeleton className="h-3 w-full" />
                    <Skeleton className="h-3 w-3/4" />
                    <div className="flex gap-2 pt-2">
                        <Skeleton className="h-6 w-16 rounded-full" />
                        <Skeleton className="h-6 w-20 rounded-full" />
                    </div>
                </Card>
            ))}
        </div>
    );
}

export function FormSkeleton({ rows = 4 }: { rows?: number }) {
    return (
        <Card className="space-y-5 p-6">
            {Array.from({ length: rows }).map((_, i) => (
                <div key={i} className="space-y-2">
                    <Skeleton className="h-3 w-24" />
                    <Skeleton className="h-9 w-full" />
                </div>
            ))}
            <div className="flex justify-end gap-2 pt-2">
                <Skeleton className="h-9 w-20" />
                <Skeleton className="h-9 w-28" />
            </div>
        </Card>
    );
}

export function MetricsSkeleton({ count = 4 }: { count?: number }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: count }).map((_, i) => (
                <Card key={i} className="space-y-3 p-5">
                    <Skeleton className="h-3 w-20" />
                    <Skeleton className="h-7 w-24" />
                    <Skeleton className="h-3 w-32" />
                </Card>
            ))}
        </div>
    );
}

/**
 * Generic page-level loading state for surfaces that don't fit the table
 * / card / form skeletons. Stacks a header skeleton plus N body blocks.
 */
export function PageSkeleton({ blocks = 3 }: { blocks?: number }) {
    return (
        <div className="space-y-6">
            <div className="space-y-2">
                <Skeleton className="h-7 w-48" />
                <Skeleton className="h-3 w-72" />
            </div>
            {Array.from({ length: blocks }).map((_, i) => (
                <Card key={i} className="space-y-3 p-6">
                    <Skeleton className="h-3 w-32" />
                    <Skeleton className="h-3 w-full" />
                    <Skeleton className="h-3 w-5/6" />
                    <Skeleton className="h-3 w-3/4" />
                </Card>
            ))}
        </div>
    );
}

type ErrorAction =
    | { label: string; onClick: () => void }
    | { label: string; href: string };

/**
 * Designed error state. Use whenever an async load fails, the API returns
 * an error envelope, or a deferred prop comes back as { error: ... }.
 */
export function ErrorState({
    title,
    description,
    action,
    children,
}: {
    title?: string;
    description?: string;
    action?: ErrorAction;
    children?: ReactNode;
}) {
    const { t } = useT();
    const resolvedTitle = title ?? t('Something went wrong');
    const resolvedDescription =
        description ??
        t(
            'We could not load this section. Try again, or refresh the page if the issue persists.',
        );

    return (
        <Card className="flex flex-col items-center gap-3 border-destructive/30 bg-destructive/5 p-10 text-center">
            <div className="rounded-full bg-destructive/10 p-3 text-destructive">
                <AlertTriangle className="size-5" />
            </div>
            <div>
                <p className="text-sm font-medium text-foreground">
                    {resolvedTitle}
                </p>
                <p className="mx-auto mt-1 max-w-sm text-xs text-muted-foreground">
                    {resolvedDescription}
                </p>
            </div>
            {action ? (
                'href' in action ? (
                    <Button asChild variant="outline" size="sm">
                        <a href={action.href}>{action.label}</a>
                    </Button>
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={action.onClick}
                    >
                        <RefreshCcw className="size-3.5" />
                        {action.label}
                    </Button>
                )
            ) : null}
            {children}
        </Card>
    );
}
