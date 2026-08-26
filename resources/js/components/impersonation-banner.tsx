import { router, usePage } from '@inertiajs/react';
import { Eye, ShieldAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { stop as impersonateStop } from '@/routes/impersonate';

type Impersonator = { id: number; name: string; email: string } | null;

export function ImpersonationBanner() {
    const { t } = useT();
    const cardRef = useRef<HTMLDivElement | null>(null);
    const { impersonating, auth } = usePage<{
        impersonating: Impersonator;
        auth: { user: { name: string; email: string } | null };
    }>().props;
    const [dragOffset, setDragOffset] = useState({ x: 0, y: 0 });
    const [isDragging, setIsDragging] = useState(false);
    const dragState = useRef<{
        pointerId: number;
        startX: number;
        startY: number;
        originX: number;
        originY: number;
    } | null>(null);

    if (!impersonating || !auth.user) {
        return null;
    }

    const clampOffset = (nextX: number, nextY: number) => {
        const card = cardRef.current;

        if (!card) {
            return { x: nextX, y: nextY };
        }

        const rect = card.getBoundingClientRect();
        const maxLeft = -(rect.left - 8);
        const maxRight = window.innerWidth - rect.right - 8;
        const maxUp = -(rect.top - 8);
        const maxDown = window.innerHeight - rect.bottom - 8;

        return {
            x: Math.min(Math.max(nextX, maxLeft), maxRight),
            y: Math.min(Math.max(nextY, maxUp), maxDown),
        };
    };

    const handlePointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
        if (event.target instanceof Element && event.target.closest('button')) {
            return;
        }

        dragState.current = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            originX: dragOffset.x,
            originY: dragOffset.y,
        };
        setIsDragging(true);
        event.currentTarget.setPointerCapture(event.pointerId);
    };

    const handlePointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
        const activeDrag = dragState.current;

        if (!activeDrag || activeDrag.pointerId !== event.pointerId) {
            return;
        }

        const next = clampOffset(
            activeDrag.originX + (event.clientX - activeDrag.startX),
            activeDrag.originY + (event.clientY - activeDrag.startY),
        );

        setDragOffset(next);
    };

    const handlePointerEnd = (event: React.PointerEvent<HTMLDivElement>) => {
        const activeDrag = dragState.current;

        if (!activeDrag || activeDrag.pointerId !== event.pointerId) {
            return;
        }

        dragState.current = null;
        setIsDragging(false);

        if (event.currentTarget.hasPointerCapture(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
    };

    return (
        <div className="pointer-events-none fixed inset-x-3 bottom-3 z-[70] flex justify-center sm:inset-x-auto sm:end-4 sm:bottom-4">
            <div
                ref={cardRef}
                className="pointer-events-auto w-full max-w-sm rounded-lg border bg-background p-3 shadow-lg"
                onPointerDown={handlePointerDown}
                onPointerMove={handlePointerMove}
                onPointerUp={handlePointerEnd}
                onPointerCancel={handlePointerEnd}
                style={{
                    transform: `translate(${dragOffset.x}px, ${dragOffset.y}px)`,
                    cursor: isDragging ? 'grabbing' : 'grab',
                    touchAction: 'none',
                }}
            >
                <div className="flex items-center gap-3">
                    <div className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-muted text-muted-foreground">
                        <Eye className="size-4" />
                    </div>

                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-foreground">
                            {t('Impersonating :name', { name: auth.user.name })}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {t('Admin: :email', {
                                email: impersonating.email,
                            })}
                        </p>
                    </div>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="shrink-0"
                        onClick={() => router.post(impersonateStop.url())}
                        title={t('Switch back to admin')}
                    >
                        <ShieldAlert className="size-4" />
                        {t('Switch back')}
                    </Button>
                </div>
            </div>
        </div>
    );
}
