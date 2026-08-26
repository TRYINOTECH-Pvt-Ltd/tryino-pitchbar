<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Standardizes the pagination metadata shape we hand to Inertia pages.
 * The frontend's TablePagination component expects exactly these four
 * fields, so wiring all index controllers through here means every
 * paginated table speaks the same protocol.
 */
class Pagination
{
    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
