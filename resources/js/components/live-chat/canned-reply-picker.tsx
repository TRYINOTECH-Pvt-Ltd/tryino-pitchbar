import { Search, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

export type CannedReplyOption = {
    id: string;
    label: string;
    content: string;
};

/**
 * Compact picker shown above the operator's reply textarea. Click the
 * "Canned reply" button to drop a search list down. Selecting a row
 * calls `onPick(content)` so the parent can fill its textarea
 * (operator can edit before sending).
 *
 * Rolled in-house instead of pulling shadcn Popover because the only
 * other dropdown surface in the admin shell is the workspace switcher,
 * which uses dropdown-menu directly. Adding Popover for one component
 * isn't worth the bundle delta.
 *
 * Renders nothing when the workspace has zero canned replies.
 */
export function CannedReplyPicker({
    replies,
    onPick,
}: {
    replies: CannedReplyOption[];
    onPick: (content: string) => void;
}) {
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const wrapRef = useRef<HTMLDivElement | null>(null);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (q === '') {
            return replies;
        }

        return replies.filter(
            (r) =>
                r.label.toLowerCase().includes(q) ||
                r.content.toLowerCase().includes(q),
        );
    }, [query, replies]);

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

    if (replies.length === 0) {
        return null;
    }

    return (
        <div ref={wrapRef} className="relative">
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="text-xs"
                onClick={() => setOpen((v) => !v)}
            >
                <Sparkles className="me-1.5 size-3.5" />
                {t('Canned reply')}
            </Button>
            {open && (
                <div className="absolute start-0 bottom-full z-50 mb-2 w-80 rounded-md border bg-popover shadow-lg">
                    <div className="border-b p-2">
                        <div className="relative">
                            <Search className="absolute start-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="text"
                                placeholder={t('Search canned replies…')}
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                className="h-8 ps-7 text-xs"
                                autoFocus
                            />
                        </div>
                    </div>
                    <div className="max-h-72 overflow-y-auto">
                        {filtered.length === 0 ? (
                            <p className="p-4 text-center text-xs text-muted-foreground">
                                {t('No matches.')}
                            </p>
                        ) : (
                            filtered.map((r) => (
                                <button
                                    key={r.id}
                                    type="button"
                                    onClick={() => {
                                        onPick(r.content);
                                        setOpen(false);
                                        setQuery('');
                                    }}
                                    className="flex w-full flex-col gap-0.5 border-b p-2.5 text-start transition last:border-b-0 hover:bg-muted"
                                >
                                    <span className="text-xs font-semibold">
                                        {r.label}
                                    </span>
                                    <span className="line-clamp-2 text-[11px] text-muted-foreground">
                                        {r.content}
                                    </span>
                                </button>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
