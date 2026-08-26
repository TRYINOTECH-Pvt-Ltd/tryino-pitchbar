import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function AdminSurface({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex min-h-0 flex-1 flex-col bg-card', className)}>
            {children}
        </div>
    );
}

export function AdminSurfaceBar({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex min-h-10 flex-wrap items-center gap-2 border-b px-3 py-2 sm:py-1.5',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function AdminSurfaceTitle({
    title,
    subtitle,
    meta,
}: {
    title: string;
    subtitle?: string;
    meta?: ReactNode;
}) {
    return (
        <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-sm font-semibold tracking-tight text-foreground sm:text-base">
                    {title}
                </h1>
                {meta}
            </div>
            {subtitle ? (
                <p className="mt-0.5 text-xs text-muted-foreground sm:text-sm">
                    {subtitle}
                </p>
            ) : null}
        </div>
    );
}
