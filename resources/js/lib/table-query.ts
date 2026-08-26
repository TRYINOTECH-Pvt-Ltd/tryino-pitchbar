import { router } from '@inertiajs/react';

type TableQueryUpdates = Record<string, string | null | undefined>;

type ReplaceTableQueryOptions = {
    only?: string[];
    resetPage?: boolean;
    pageParamName?: string;
};

export function buildTableQueryUrl(
    updates: TableQueryUpdates,
    {
        resetPage = false,
        pageParamName = 'page',
    }: ReplaceTableQueryOptions = {},
): string {
    const params = new URLSearchParams(window.location.search);

    if (resetPage) {
        params.delete(pageParamName);
    }

    Object.entries(updates).forEach(([key, value]) => {
        const normalized = value?.trim();

        if (!normalized) {
            params.delete(key);

            return;
        }

        params.set(key, normalized);
    });

    const query = params.toString();

    return window.location.pathname + (query === '' ? '' : `?${query}`);
}

export function replaceTableQuery(
    updates: TableQueryUpdates,
    options: ReplaceTableQueryOptions = {},
): void {
    router.get(buildTableQueryUrl(updates, options), undefined, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: options.only,
    });
}
