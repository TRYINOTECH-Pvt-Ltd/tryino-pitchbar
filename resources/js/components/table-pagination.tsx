import { DirArrow } from '@/components/dir-icon';
import { useT } from '@/lib/i18n';
import { replaceTableQuery } from '@/lib/table-query';

export type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type Props = {
    /**
     * Pagination metadata from a Laravel LengthAwarePaginator. Pass
     * `paginator()->toArray()` keys as-is or build the object server-side
     * with the controller helper.
     */
    pagination: PaginationMeta;
    /**
     * Inertia partial-reload prop names. Same idea as TableSearch's `only`
     * — keeps page-flip visits cheap by only re-fetching the rows that
     * actually changed.
     */
    only?: string[];
    /** URL parameter name. Defaults to `page`. */
    paramName?: string;
};

/**
 * Page navigator for paginated index pages. It also doubles as the
 * compact table status footer for single-page result sets.
 */
export function TablePagination({
    pagination,
    only,
    paramName = 'page',
}: Props) {
    const { t } = useT();
    const { current_page, last_page, per_page, total } = pagination;

    const goTo = (page: number) => {
        if (page < 1 || page > last_page || page === current_page) {
            return;
        }

        replaceTableQuery(
            {
                [paramName]: page <= 1 ? null : String(page),
            },
            {
                only,
                resetPage: false,
                pageParamName: paramName,
            },
        );
    };

    const start = total === 0 ? 0 : (current_page - 1) * per_page + 1;
    const end = total === 0 ? 0 : Math.min(current_page * per_page, total);

    return (
        <div className="flex min-h-8 items-center justify-between gap-3 border-t bg-muted/20 px-4 py-1.5 text-xs text-muted-foreground">
            <span>
                {t('Showing')}{' '}
                <span className="font-medium text-foreground">{start}</span>–
                <span className="font-medium text-foreground">{end}</span>{' '}
                {t('of')}{' '}
                <span className="font-medium text-foreground">{total}</span>
            </span>
            <div className="flex items-center gap-1">
                <button
                    type="button"
                    onClick={() => goTo(current_page - 1)}
                    disabled={current_page <= 1}
                    aria-label={t('Previous page')}
                    className="flex size-6 items-center justify-center rounded-md border bg-card text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <DirArrow
                        direction="back"
                        style="chevron"
                        className="size-4"
                    />
                </button>
                <span className="px-2 tabular-nums">
                    {current_page} / {last_page}
                </span>
                <button
                    type="button"
                    onClick={() => goTo(current_page + 1)}
                    disabled={current_page >= last_page}
                    aria-label={t('Next page')}
                    className="flex size-6 items-center justify-center rounded-md border bg-card text-foreground transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <DirArrow
                        direction="forward"
                        style="chevron"
                        className="size-4"
                    />
                </button>
            </div>
        </div>
    );
}
