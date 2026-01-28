<?php

namespace App\Http\Controllers;

use App\Models\BlockedTerm;
use App\Services\PaginationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BlockedTermController extends Controller
{
    public function __construct(
        protected PaginationService $paginationService
    ) {
    }

    /**
     * List all blocked terms with pagination for admin
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $pagination = $this->paginationService->extractParams($request);
        $sort = $request->input('sort', 'term');
        $order = $request->input('order', 'asc');
        $filter = $request->input('filter', []);

        $query = BlockedTerm::query();

        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?? [];
        }

        if (!empty($filter['q'])) {
            $query->where('term', 'like', '%' . $filter['q'] . '%');
        }

        if (!empty($filter['category'])) {
            $query->where('category', $filter['category']);
        }

        if (isset($filter['is_active'])) {
            $query->where('is_active', $filter['is_active']);
        }

        $query->orderBy($sort, $order);

        $total = $query->count();
        $terms = $this->paginationService->applyToQuery($query, $pagination['offset'], $pagination['per_page'])->get();

        return response()->json([
            'data' => $terms,
            'total' => $total,
        ]);
    }

    /**
     * Create a new blocked term
     */
    public function adminStore(Request $request): JsonResponse
    {
        $request->validate([
            'term' => 'required|string|max:100|unique:blocked_terms,term',
            'category' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $term = BlockedTerm::create([
            'term' => strtolower(trim($request->input('term'))),
            'category' => $request->input('category'),
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'data' => $term,
        ], 201);
    }

    /**
     * Get a single blocked term
     */
    public function adminShow(int $id): JsonResponse
    {
        $term = BlockedTerm::find($id);

        if (!$term) {
            return response()->json([
                'success' => false,
                'message' => 'Blocked term not found',
            ], 404);
        }

        return response()->json([
            'data' => $term,
        ]);
    }

    /**
     * Update a blocked term
     */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $term = BlockedTerm::find($id);

        if (!$term) {
            return response()->json([
                'success' => false,
                'message' => 'Blocked term not found',
            ], 404);
        }

        $request->validate([
            'term' => 'string|max:100|unique:blocked_terms,term,' . $id,
            'category' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $data = $request->only(['term', 'category', 'is_active']);
        if (isset($data['term'])) {
            $data['term'] = strtolower(trim($data['term']));
        }
        $term->update($data);

        return response()->json([
            'data' => $term,
        ]);
    }

    /**
     * Delete a blocked term
     */
    public function adminDestroy(int $id): JsonResponse
    {
        $term = BlockedTerm::find($id);

        if (!$term) {
            return response()->json([
                'success' => false,
                'message' => 'Blocked term not found',
            ], 404);
        }

        $term->delete();

        return response()->json([
            'data' => ['id' => $id],
        ]);
    }
}
