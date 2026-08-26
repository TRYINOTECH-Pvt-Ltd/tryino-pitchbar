import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';

/**
 * Shared empty-state primitive — every "no rows yet" surface in the
 * customer app should use this so they look consistent and tell the
 * user what to do next instead of just printing "None yet."
 *
 * Usage:
 *   <EmptyState
 *     icon={Bot}
 *     title="No CTAs configured"
 *     description="CTAs are buttons the bot can show during a chat to push the visitor toward a demo or signup."
 *     action={{ label: 'Add your first CTA', href: '/app/agents/X/ctas/new' }}
 *   />
 */
type Action =
    | { label: string; href: string }
    | { label: string; onClick: () => void };

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    action?: Action;
    children?: ReactNode;
}) {
    return (
        <Card className="flex flex-col items-center justify-center gap-3 p-12 text-center">
            <div className="rounded-full bg-muted p-3">
                <Icon className="size-5 text-muted-foreground" />
            </div>
            <div>
                <p className="text-sm font-medium">{title}</p>
                {description && (
                    <p className="mx-auto mt-1 max-w-sm text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action &&
                ('href' in action ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={action.href}>{action.label}</Link>
                    </Button>
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={action.onClick}
                    >
                        {action.label}
                    </Button>
                ))}
            {children}
        </Card>
    );
}
