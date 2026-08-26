import { Plus, Tag as TagIcon, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

export type TagOption = {
    id: string;
    label: string;
    color: string;
};

/**
 * Inline tag editor for the conversation context panel. Shows already-
 * applied tags as chips with an X to remove; "+ Add tag" opens a small
 * fuzzy-search dropdown of unselected workspace tags.
 */
export function TagPicker({
    applied,
    available,
    onAttach,
    onDetach,
}: {
    applied: TagOption[];
    available: TagOption[];
    onAttach: (tagId: string) => void;
    onDetach: (tagId: string) => void;
}) {
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const wrapRef = useRef<HTMLDivElement | null>(null);

    const appliedIds = useMemo(
        () => new Set(applied.map((tag) => tag.id)),
        [applied],
    );
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        return available
            .filter((tag) => !appliedIds.has(tag.id))
            .filter((tag) => q === '' || tag.label.toLowerCase().includes(q));
    }, [query, available, appliedIds]);

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

    return (
        <div ref={wrapRef} className="relative">
            <div className="flex flex-wrap gap-1.5">
                {applied.map((tag) => (
                    <button
                        key={tag.id}
                        type="button"
                        onClick={() => onDetach(tag.id)}
                        className="group/tag inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold text-white transition hover:opacity-90"
                        style={{ background: tag.color }}
                        title={t('Remove tag')}
                    >
                        {tag.label}
                        <X className="size-3 opacity-0 transition group-hover/tag:opacity-100" />
                    </button>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen((v) => !v)}
                    className="h-6 rounded-full px-2 text-[11px]"
                >
                    <Plus className="me-1 size-3" />
                    {applied.length === 0 ? t('Add tag') : t('Tag')}
                </Button>
            </div>

            {open && (
                <div className="absolute start-0 top-full z-50 mt-1 w-72 rounded-md border bg-popover shadow-lg">
                    <div className="border-b p-2">
                        <Input
                            type="text"
                            placeholder={t('Search tags…')}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            className="h-8 text-xs"
                            autoFocus
                        />
                    </div>
                    <div className="max-h-64 overflow-y-auto">
                        {filtered.length === 0 ? (
                            <p className="p-4 text-center text-xs text-muted-foreground">
                                {available.length === 0
                                    ? t('No tags configured.')
                                    : t(
                                          'All available tags are already applied.',
                                      )}
                            </p>
                        ) : (
                            filtered.map((tag) => (
                                <button
                                    key={tag.id}
                                    type="button"
                                    onClick={() => {
                                        onAttach(tag.id);
                                        setOpen(false);
                                        setQuery('');
                                    }}
                                    className="flex w-full items-center gap-2 border-b p-2 text-start text-xs transition last:border-b-0 hover:bg-muted"
                                >
                                    <span
                                        className="size-3 shrink-0 rounded-full"
                                        style={{ background: tag.color }}
                                    />
                                    <TagIcon className="size-3 text-muted-foreground" />
                                    <span className="font-medium">
                                        {tag.label}
                                    </span>
                                </button>
                            ))
                        )}
                    </div>
                    {/* Persistent footer link — buyer-reported (Lucian,
                        2026-05-15): after creating ONE tag the empty-state
                        "Create tags →" link disappeared, leaving no way to
                        reach /app/settings/tags from the conversation
                        panel. Footer keeps the link visible regardless of
                        list state. */}
                    <div className="border-t bg-muted/30 p-2 text-center">
                        <a
                            href="/app/settings/tags"
                            className="text-xs font-medium text-primary underline-offset-4 hover:underline"
                        >
                            {available.length === 0
                                ? t('Create tags →')
                                : t('Manage tags →')}
                        </a>
                    </div>
                </div>
            )}
        </div>
    );
}
