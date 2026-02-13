<?php

namespace App\Http\Controllers;

use App\Jobs\IndexTattooJob;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Services\TagService;
use App\Services\PaginationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TagController extends Controller
{
    public function __construct(
        protected TagService $tagService,
        protected PaginationService $paginationService
    ) {
    }

    /**
     * Get all approved tags that have at least one tattoo (for listing)
     * Optionally filter by specific IDs with ?ids=1,2,3
     */
    public function index(Request $request): JsonResponse
    {
        $idsParam = $request->input('ids');

        if ($idsParam) {
            $ids = array_filter(array_map('intval', explode(',', $idsParam)));
            $tags = Tag::whereIn('id', $ids)->get();

            return response()->json([
                'success' => true,
                'data' => $tags
            ]);
        }

        // Cache all tags for 30 minutes
        $tags = Cache::remember('tags:approved:all', 1800, function () {
            return Tag::approved()
                ->whereHas('tattoos')
                ->withCount('tattoos')
                ->orderBy('name')
                ->get();
        });

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
     * Get featured/popular approved tags that have tattoos (for homepage)
     */
    public function featured(Request $request): JsonResponse
    {
        $limit = min($request->input('limit', 15), 30);

        // Cache featured tags for 15 minutes (keyed by limit)
        $tags = Cache::remember("tags:featured:{$limit}", 900, function () use ($limit) {
            return Tag::approved()
                       ->whereHas('tattoos')
                       ->withCount('tattoos')
                       ->orderBy('tattoos_count', 'desc')
                       ->limit($limit)
                       ->get();
        });

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
        $user = $request->user();

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

        IndexTattooJob::dispatch($tattoo->id);

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
        $user = $request->user();

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

        IndexTattooJob::dispatch($tattoo->id);

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
        $user = $request->user();

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

        IndexTattooJob::dispatch($tattoo->id);

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
        $user = $request->user();

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

        IndexTattooJob::dispatch($tattoo->id);

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
        $user = $request->user();

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

    /**
     * Create a tag from an AI suggestion (user accepted it).
     * Creates as approved with is_ai_generated = true.
     */
    public function createFromAiSuggestion(Request $request): JsonResponse
    {
        $user = $request->user();

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
        $tag = $this->tagService->createFromAiSuggestion($name);

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid tag name'
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $tag,
            'message' => 'Tag created from AI suggestion'
        ]);
    }

    /**
     * Get AI tag suggestions for images (without creating a tattoo).
     * Used during the upload flow to show suggestions while user is selecting tags.
     */
    public function suggestFromImages(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $request->validate([
            'image_urls' => 'required|array|min:1|max:10',
            'image_urls.*' => 'required|string|url'
        ]);

        $imageUrls = $request->input('image_urls');

        try {
            $suggestions = $this->tagService->suggestTagsForImages($imageUrls);

            return response()->json([
                'success' => true,
                'data' => array_map(fn($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'is_ai_suggested' => true,
                ], $suggestions)
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate AI tag suggestions', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to analyze images',
                'data' => []
            ], 500);
        }
    }

    // ==================== Admin Methods ====================

    /**
     * List all tags with pagination for admin (including pending)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $pagination = $this->paginationService->extractParams($request);
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

        if (isset($filter['is_ai_generated'])) {
            $query->where('is_ai_generated', $filter['is_ai_generated']);
        }

        // Apply sorting
        $query->orderBy($sort, $order);

        $total = $query->count();
        $tags = $this->paginationService->applyToQuery($query, $pagination['offset'], $pagination['per_page'])->get();

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
