<?php

namespace App\Services;

use Illuminate\Http\Request;

class PaginationService
{
    private int $defaultPerPage = 25;
    private int $maxPerPage = 100;

    /**
     * Extract pagination parameters from a Request or array.
     *
     * @param Request|array $input
     * @return array{page: int, per_page: int, offset: int}
     */
    public function extractParams(Request|array $input): array
    {
        if ($input instanceof Request) {
            $page = max(1, (int) $input->input('page', 1));
            $perPage = min($this->maxPerPage, max(1, (int) $input->input('per_page', $this->defaultPerPage)));
        } else {
            $page = isset($input['page']) ? max(1, (int) $input['page']) : 1;
            $perPage = isset($input['per_page'])
                ? min($this->maxPerPage, max(1, (int) $input['per_page']))
                : $this->defaultPerPage;
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    /**
     * Build standard pagination metadata for API responses.
     *
     * @param int $total Total number of items
     * @param int $page Current page number
     * @param int $perPage Items per page
     * @return array{total: int, page: int, per_page: int, has_more: bool}
     */
    public function buildMeta(int $total, int $page, int $perPage): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
        ];
    }

    /**
     * Apply pagination to an Eloquent query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $offset
     * @param int $perPage
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyToQuery($query, int $offset, int $perPage)
    {
        return $query->skip($offset)->take($perPage);
    }
}
