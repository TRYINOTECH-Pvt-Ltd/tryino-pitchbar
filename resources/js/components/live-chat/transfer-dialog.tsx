import { Headset, Users, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

export type TransferTarget = {
    id: number;
    name: string;
    is_online: boolean;
};

/**
 * Lightweight dropdown that lists workspace teammates and lets the
 * current operator transfer the conversation. Online operators are
 * grouped at the top; offline ones still appear (operator may want
 * to assign a queue to someone who'll claim later).
 */
export function TransferDialog({
    teammates,
    onTransfer,
    busy = false,
}: {
    teammates: TransferTarget[];
    onTransfer: (userId: number) => void;
    busy?: boolean;
}) {
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const wrapRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onClick = (e: MouseEvent) => {
            if (
                wrapRef.current &&
                !wrapRef.current.contains(e.target as Node)
            ) {
                setOpen(false);
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const online = teammates.filter((t) => t.is_online);
    const offline = teammates.filter((t) => !t.is_online);

    return (
        <div ref={wrapRef} className="relative">
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setOpen((v) => !v)}
                disabled={busy}
                title={t('Transfer this conversation')}
            >
                <Users className="me-1.5 size-3.5" />
                {t('Transfer')}
            </Button>

            {open && (
                <div className="absolute end-0 top-full z-50 mt-1 w-72 rounded-md border bg-popover shadow-lg">
                    <div className="flex items-center justify-between border-b p-2.5">
                        <span className="text-xs font-semibold">
                            {t('Transfer to…')}
                        </span>
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            className="rounded p-0.5 hover:bg-muted"
                        >
                            <X className="size-3.5 text-muted-foreground" />
                        </button>
                    </div>
                    <div className="max-h-72 overflow-y-auto">
                        {online.length > 0 && (
                            <div>
                                <p className="bg-muted/40 px-2.5 py-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    {t('Online')}
                                </p>
                                {online.map((member) => (
                                    <button
                                        key={member.id}
                                        type="button"
                                        onClick={() => {
                                            onTransfer(member.id);
                                            setOpen(false);
                                        }}
                                        className="flex w-full items-center justify-between border-b p-2.5 text-start text-sm transition last:border-b-0 hover:bg-muted"
                                    >
                                        <span className="flex items-center gap-2">
                                            <Headset className="size-3.5 text-emerald-600" />
                                            {member.name}
                                        </span>
                                        <Badge
                                            variant="default"
                                            className="bg-emerald-600 text-[10px] text-white"
                                        >
                                            {t('Online')}
                                        </Badge>
                                    </button>
                                ))}
                            </div>
                        )}
                        {offline.length > 0 && (
                            <div>
                                <p className="bg-muted/40 px-2.5 py-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    {t('Offline')}
                                </p>
                                {offline.map((member) => (
                                    <button
                                        key={member.id}
                                        type="button"
                                        onClick={() => {
                                            onTransfer(member.id);
                                            setOpen(false);
                                        }}
                                        className="flex w-full items-center justify-between border-b p-2.5 text-start text-sm transition last:border-b-0 hover:bg-muted"
                                    >
                                        <span className="text-muted-foreground">
                                            {member.name}
                                        </span>
                                        <span className="text-[10px] text-muted-foreground">
                                            {t('Offline')}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {teammates.length === 0 && (
                            <div className="space-y-2 p-4 text-center">
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'No teammates yet. Invite a workspace member with Editor, Admin, or Owner role to transfer conversations.',
                                    )}
                                </p>
                                <a
                                    href="/settings/members"
                                    className="inline-block text-xs font-medium text-primary hover:underline"
                                >
                                    {t('Invite teammates →')}
                                </a>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
