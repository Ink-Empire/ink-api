<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\Tattoo;
use App\Services\TagService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    protected TagService $tagService;

    public function __construct(TagService $tagService)
    {
        $this->tagService = $tagService;
    }

    /**
     * Get all approved tags (for listing)
     */
    public function index(): JsonResponse
    {
        $tags = Tag::approved()->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $tags
        ]);
    }

    /**
     * Search tags by name (for autocomplete)
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $limit = min($request->input('limit', 10), 50);

        if (strlen($query) < 1) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        $tags = Tag::search($query)
                   ->limit($limit)
                   ->get();

        return response()->json([
            'success' => true,
            'data' => $tags
        ]);
    }

    /**
     * Get featured/popular approved tags (for homepage)
     */
    public function featured(Request $request): JsonResponse
    {
        $limit = min($request->input('limit', 15), 30);

        $tags = Tag::approved()
                   ->withCount('tattoos')
                   ->orderBy('tattoos_count', 'desc')
                   ->limit($limit)
                   ->get();

        return response()->json([
            'success' => true,
            'data' => $tags
        ]);
    }

    /**
     * Get a single approved tag by slug
     */
    public function show(string $slug): JsonResponse
    {
        $tag = Tag::approved()->where('slug', $slug)->first();

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tag not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $tag
        ]);
    }

    /**
     * Get tags for a specific tattoo
     */
    public function getTattooTags(int $tattooId): JsonResponse
    {
        $tattoo = Tattoo::find($tattooId);

        if (!$tattoo) {
            return response()->json([
                'success' => false,
                'message' => 'Tattoo not found'
            ], 404);
        }

        $tags = $tattoo->tags;

        return response()->json([
            'success' => true,
            'data' => $tags
        ]);
    }

    /**
     * Set tags for a tattoo (artist only)
     */
    public function setTattooTags(Request $request, int $tattooId): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $tattoo = Tattoo::find($tattooId);

        if (!$tattoo) {
            return response()->json([
                'success' => false,
                'message' => 'Tattoo not found'
            ], 404);
        }

        // Check if user is the tattoo's artist
        if ($tattoo->artist_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only manage tags for your own tattoos'
            ], 403);
        }

        $request->validate([
            'tag_ids' => 'required|array|max:10',
            'tag_ids.*' => 'integer|exists:tags,id'
        ]);

        $tagIds = $request->input('tag_ids', []);
        $tags = $this->tagService->setTagsForTattoo($tattoo, $tagIds);

        // Re-index tattoo for search
        $tattoo->searchable();

        return response()->json([
            'success' => true,
            'message' => 'Tags updated successfully',
            'data' => $tags
        ]);
    }

    /**
     * Add a single tag to a tattoo
     */
    public function addTattooTag(Request $request, int $tattooId): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $tattoo = Tattoo::find($tattooId);

        if (!$tattoo) {
            return response()->json([
                'success' => false,
                'message' => 'Tattoo not found'
            ], 404);
        }

        // Check if user is the tattoo's artist
        if ($tattoo->artist_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only manage tags for your own tattoos'
            ], 403);
        }

        // Check tag limit (max 10)
        if ($tattoo->tags()->count() >= 10) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum of 10 tags allowed per tattoo'
            ], 422);
        }

        $request->validate([
            'tag_id' => 'required|integer|exists:tags,id'
        ]);

        $tagId = $request->input('tag_id');
        $tag = $this->tagService->addTagToTattoo($tattoo, $tagId);

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tag not found'
            ], 404);
        }

        // Re-index tattoo for search
        $tattoo->searchable();

        return response()->json([
            'success' => true,
            'message' => 'Tag added successfully',
            'data' => $tag
        ]);
    }

    /**
     * Remove a tag from a tattoo
     */
    public function removeTattooTag(int $tattooId, int $tagId): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $tattoo = Tattoo::find($tattooId);

        if (!$tattoo) {
            return response()->json([
                'success' => false,
                'message' => 'Tattoo not found'
            ], 404);
        }

        // Check if user is the tattoo's artist
        if ($tattoo->artist_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only manage tags for your own tattoos'
            ], 403);
        }

        $this->tagService->removeTagFromTattoo($tattoo, $tagId);

        // Re-index tattoo for search
        $tattoo->searchable();

        return response()->json([
            'success' => true,
            'message' => 'Tag removed successfully'
        ]);
    }

    /**
     * Generate AI tags for a tattoo
     */
    public function generateTattooTags(int $tattooId): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $tattoo = Tattoo::find($tattooId);

        if (!$tattoo) {
            return response()->json([
                'success' => false,
                'message' => 'Tattoo not found'
            ], 404);
        }

        // Check if user is the tattoo's artist
        if ($tattoo->artist_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only generate tags for your own tattoos'
            ], 403);
        }

        $tags = $this->tagService->generateTagsForTattoo($tattoo);

        // Re-index tattoo for search
        $tattoo->searchable();

        return response()->json([
            'success' => true,
            'message' => 'Tags generated successfully',
            'data' => $tags
        ]);
    }

    /**
     * Create a new tag (will be pending until approved)
     */
    public function create(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $request->validate([
            'name' => 'required|string|min:2|max:50'
        ]);

        $name = trim($request->input('name'));

        // Use findOrCreateByName which handles existing tags and creates new ones as pending
        $tag = Tag::findOrCreateByName($name);

        return response()->json([
            'success' => true,
            'data' => $tag,
            'is_new' => $tag->wasRecentlyCreated,
            'message' => $tag->is_pending
                ? 'Tag created and pending approval'
                : 'Tag found'
        ]);
    }

    // ==================== Admin Methods ====================

    /**
     * List all tags with pagination for admin (including pending)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 25), 100);
        $page = $request->input('page', 1);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'desc');
        $filter = $request->input('filter', []);

        $query = Tag::withCount('tattoos');

        // Apply filters
        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?? [];
        }

        if (!empty($filter['q'])) {
            $search = $filter['q'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (isset($filter['is_pending'])) {
            $query->where('is_pending', $filter['is_pending']);
        }

        // Apply sorting
        $query->orderBy($sort, $order);

        $total = $query->count();
        $tags = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'data' => $tags,
            'total' => $total,
        ]);
    }

    /**
     * Create a new tag (admin only)
     */
    public function adminStore(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|min:2|max:50',
        ]);

        $name = trim($request->input('name'));
        $slug = strtolower(str_replace(' ', '-', $name));

        // Check if tag already exists
        $existing = Tag::where('slug', $slug)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Tag already exists',
            ], 422);
        }

        $tag = Tag::create([
            'name' => $name,
            'slug' => $slug,
            'is_pending' => $request->input('is_pending', false),
        ]);

        return response()->json([
            'data' => $tag,
        ], 201);
    }

    /**
     * Get a single tag for admin
     */
    public function adminShow(int $id): JsonResponse
    {
        $tag = Tag::withCount('tattoos')->find($id);

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tag not found',
            ], 404);
        }

        return response()->json([
            'data' => $tag,
        ]);
    }

    /**
     * Update a tag (admin only)
     */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tag not found',
            ], 404);
        }

        $data = $request->all();

        foreach ($data as $fieldName => $fieldVal) {
            if (in_array($fieldName, $tag->getFillable())) {
                $tag->{$fieldName} = $fieldVal;
            }
        }

        $tag->save();

        return response()->json([
            'data' => $tag,
        ]);
    }

    /**
     * Delete a tag (admin only)
     */
    public function adminDestroy(int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tag not found',
            ], 404);
        }

        // Detach from all tattoos first
        $tag->tattoos()->detach();
        $tag->delete();

        return response()->json([
            'data' => ['id' => $id],
        ]);
    }

    /**
     * Approve a pending tag (admin only)
     */
    public function approve(int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tag not found',
            ], 404);
        }

        $tag->is_pending = false;
        $tag->save();

        return response()->json([
            'success' => true,
            'data' => $tag,
            'message' => 'Tag approved successfully',
        ]);
    }

    /**
     * Reject a pending tag (admin only) - deletes the tag
     */
    public function reject(int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tag not found',
            ], 404);
        }

        // Detach from all tattoos first
        $tag->tattoos()->detach();
        $tag->delete();

        return response()->json([
            'success' => true,
            'data' => ['id' => $id],
            'message' => 'Tag rejected and deleted',
        ]);
    }
}
