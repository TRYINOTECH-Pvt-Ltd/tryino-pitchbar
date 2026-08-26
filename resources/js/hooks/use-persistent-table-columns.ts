import { useEffect, useMemo, useRef, useState } from 'react';

export type TableColumnOption<Id extends string> = {
    id: Id;
    label: string;
    canHide?: boolean;
    defaultVisible?: boolean;
};

function normalizeVisibleColumns<Id extends string>(
    columns: readonly TableColumnOption<Id>[],
    candidateIds: Iterable<Id>,
): Id[] {
    const visibleSet = new Set(candidateIds);

    columns.forEach((column) => {
        if (column.canHide === false) {
            visibleSet.add(column.id);
        }
    });

    const orderedVisibleColumns = columns
        .filter((column) => visibleSet.has(column.id))
        .map((column) => column.id);

    if (orderedVisibleColumns.length > 0) {
        return orderedVisibleColumns;
    }

    return columns
        .filter((column) => column.defaultVisible !== false)
        .map((column) => column.id);
}

function getDefaultVisibleColumns<Id extends string>(
    columns: readonly TableColumnOption<Id>[],
): Id[] {
    return columns
        .filter((column) => column.defaultVisible !== false)
        .map((column) => column.id);
}

export function usePersistentTableColumns<Id extends string>(
    storageKey: string,
    columns: readonly TableColumnOption<Id>[],
) {
    // The hook is called with an inline-built `columns` array on most
    // consumers (e.g. inboxColumns(t) runs each render → new ref every
    // pass). Depending on the array reference directly meant the load
    // effect re-fired on every parent re-render, which then called
    // setVisibleColumns, which triggered another render, which built a
    // new columns ref, which re-fired the effect — an infinite loop.
    // Client report 2026-05-22: Inbox crashed when toggling the
    // Compact/Comfortable density buttons. Stabilising the dep on a
    // SHAPE signature (ordered column ids + flags) breaks the loop —
    // adding/removing/renaming a column still reruns the effect; a
    // pure re-render does not.
    const columnsSignature = useMemo(
        () =>
            columns
                .map(
                    (column) =>
                        `${column.id}:${column.canHide === false ? '1' : '0'}:${column.defaultVisible === false ? '0' : '1'}`,
                )
                .join('|'),
        [columns],
    );

    const defaultVisibleColumns = useMemo(
        () => getDefaultVisibleColumns(columns),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- columnsSignature is the stable string identity of columns
        [columnsSignature],
    );

    const [visibleColumns, setVisibleColumns] = useState<Id[]>(
        defaultVisibleColumns,
    );
    const hasLoadedPreferences = useRef(false);
    const columnsRef = useRef(columns);
    useEffect(() => {
        columnsRef.current = columns;
    }, [columns]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const liveColumns = columnsRef.current;
        const fallbackColumns = getDefaultVisibleColumns(liveColumns);
        const storedColumns = window.localStorage.getItem(storageKey);

        if (!storedColumns) {
            hasLoadedPreferences.current = true;

            return;
        }

        let cancelled = false;

        try {
            const parsedColumns = JSON.parse(storedColumns);

            if (!Array.isArray(parsedColumns)) {
                hasLoadedPreferences.current = true;

                return;
            }

            const knownColumnIds = liveColumns.map((column) => column.id);
            const nextVisibleColumns = parsedColumns.filter(
                (columnId): columnId is Id => knownColumnIds.includes(columnId),
            );

            queueMicrotask(() => {
                if (cancelled) {
                    return;
                }

                hasLoadedPreferences.current = true;
                setVisibleColumns(
                    normalizeVisibleColumns(liveColumns, nextVisibleColumns),
                );
            });
        } catch {
            queueMicrotask(() => {
                if (cancelled) {
                    return;
                }

                hasLoadedPreferences.current = true;
                setVisibleColumns(fallbackColumns);
            });
        }

        return () => {
            cancelled = true;
        };
    }, [columnsSignature, storageKey]);

    useEffect(() => {
        if (typeof window === 'undefined' || !hasLoadedPreferences.current) {
            return;
        }

        window.localStorage.setItem(storageKey, JSON.stringify(visibleColumns));
    }, [storageKey, visibleColumns]);

    const setColumnVisibility = (id: Id, visible: boolean) => {
        const column = columns.find((entry) => entry.id === id);

        if (!column || column.canHide === false) {
            return;
        }

        setVisibleColumns((currentColumns) => {
            const nextColumns = new Set(currentColumns);

            if (visible) {
                nextColumns.add(id);
            } else {
                nextColumns.delete(id);
            }

            return normalizeVisibleColumns(columns, nextColumns);
        });
    };

    return {
        visibleColumns,
        hiddenColumnCount: columns.length - visibleColumns.length,
        isColumnVisible: (id: Id) => visibleColumns.includes(id),
        resetColumns: () =>
            setVisibleColumns(getDefaultVisibleColumns(columnsRef.current)),
        setColumnVisibility,
    };
}
