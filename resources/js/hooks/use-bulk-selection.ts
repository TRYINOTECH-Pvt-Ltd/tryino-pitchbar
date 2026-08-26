import { useCallback, useMemo, useRef, useState } from 'react';

/**
 * Generic bulk-selection state for index tables. Tracks a Set<string>
 * of selected row ids. Plays well with paginated lists — selections
 * persist across pages until cleared. Header tri-state derives from
 * the current page's id slice, not the whole selection.
 *
 * Shift-click range select: the hook remembers the last clicked id;
 * a shift+click on a row toggles every row BETWEEN the two within the
 * supplied `pageIds` array. Caller passes `pageIds` on each toggle
 * so the hook can compute the range.
 */
export function useBulkSelection() {
    const [selected, setSelected] = useState<Set<string>>(() => new Set());
    const lastClickedRef = useRef<string | null>(null);

    const size = selected.size;

    const clear = useCallback(() => {
        setSelected(new Set());
        lastClickedRef.current = null;
    }, []);

    const isSelected = useCallback(
        (id: string) => selected.has(id),
        [selected],
    );

    const toggle = useCallback(
        (id: string, opts?: { shiftKey?: boolean; pageIds?: string[] }) => {
            setSelected((prev) => {
                const next = new Set(prev);
                const last = lastClickedRef.current;

                if (
                    opts?.shiftKey &&
                    last &&
                    last !== id &&
                    opts.pageIds?.length
                ) {
                    const ids = opts.pageIds;
                    const a = ids.indexOf(last);
                    const b = ids.indexOf(id);

                    if (a !== -1 && b !== -1) {
                        const [start, end] = a < b ? [a, b] : [b, a];
                        const turningOn = !prev.has(id);

                        for (let i = start; i <= end; i++) {
                            if (turningOn) {
                                next.add(ids[i]);
                            } else {
                                next.delete(ids[i]);
                            }
                        }

                        lastClickedRef.current = id;

                        return next;
                    }
                }

                if (next.has(id)) {
                    next.delete(id);
                } else {
                    next.add(id);
                }

                lastClickedRef.current = id;

                return next;
            });
        },
        [],
    );

    const selectAll = useCallback((ids: string[]) => {
        setSelected((prev) => {
            const next = new Set(prev);
            ids.forEach((id) => next.add(id));

            return next;
        });
    }, []);

    const deselectAll = useCallback((ids: string[]) => {
        setSelected((prev) => {
            const next = new Set(prev);
            ids.forEach((id) => next.delete(id));

            return next;
        });
    }, []);

    const allOnPageSelected = useCallback(
        (pageIds: string[]) =>
            pageIds.length > 0 && pageIds.every((id) => selected.has(id)),
        [selected],
    );

    const someOnPageSelected = useCallback(
        (pageIds: string[]) => pageIds.some((id) => selected.has(id)),
        [selected],
    );

    const togglePage = useCallback(
        (pageIds: string[]) => {
            const allSelected =
                pageIds.length > 0 && pageIds.every((id) => selected.has(id));

            if (allSelected) {
                deselectAll(pageIds);
            } else {
                selectAll(pageIds);
            }
        },
        [selected, selectAll, deselectAll],
    );

    return useMemo(
        () => ({
            selected,
            size,
            clear,
            isSelected,
            toggle,
            selectAll,
            deselectAll,
            togglePage,
            allOnPageSelected,
            someOnPageSelected,
        }),
        [
            selected,
            size,
            clear,
            isSelected,
            toggle,
            selectAll,
            deselectAll,
            togglePage,
            allOnPageSelected,
            someOnPageSelected,
        ],
    );
}

export type BulkSelection = ReturnType<typeof useBulkSelection>;
