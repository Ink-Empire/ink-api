<?php

namespace App\Http\Controllers;

use App\Models\Placement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PlacementController extends Controller
{
    /**
     * Get all active placements (public endpoint, cached)
     */
    public function index(): JsonResponse
    {
        $placements = Placement::getActivePlacements();

        return response()->json([
            'success' => true,
            'data' => $placements
        ]);
    }

    // ==================== Admin Methods ====================

    /**
     * List all placements with pagination for admin
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 25), 100);
        $page = $request->input('page', 1);
        $sort = $request->input('sort', 'sort_order');
        $order = $request->input('order', 'asc');
        $filter = $request->input('filter', []);

        $query = Placement::query();

        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?? [];
        }

        if (!empty($filter['q'])) {
            $query->where('name', 'like', '%' . $filter['q'] . '%');
        }

        if (isset($filter['is_active'])) {
            $query->where('is_active', $filter['is_active']);
        }

        $query->orderBy($sort, $order);

        $total = $query->count();
        $placements = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'data' => $placements,
            'total' => $total,
        ]);
    }

    /**
     * Create a new placement
     */
    public function adminStore(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $placement = Placement::create([
            'name' => $request->input('name'),
            'sort_order' => $request->input('sort_order', 0),
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'data' => $placement,
        ], 201);
    }

    /**
     * Get a single placement
     */
    public function adminShow(int $id): JsonResponse
    {
        $placement = Placement::find($id);

        if (!$placement) {
            return response()->json([
                'success' => false,
                'message' => 'Placement not found',
            ], 404);
        }

        return response()->json([
            'data' => $placement,
        ]);
    }

    /**
     * Update a placement
     */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $placement = Placement::find($id);

        if (!$placement) {
            return response()->json([
                'success' => false,
                'message' => 'Placement not found',
            ], 404);
        }

        $data = $request->only(['name', 'slug', 'sort_order', 'is_active']);
        $placement->update($data);

        return response()->json([
            'data' => $placement,
        ]);
    }

    /**
     * Delete a placement
     */
    public function adminDestroy(int $id): JsonResponse
    {
        $placement = Placement::find($id);

        if (!$placement) {
            return response()->json([
                'success' => false,
                'message' => 'Placement not found',
            ], 404);
        }

        $placement->delete();

        return response()->json([
            'data' => ['id' => $id],
        ]);
    }
}
