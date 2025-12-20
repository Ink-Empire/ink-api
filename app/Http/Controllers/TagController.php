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
     * Get all tags (for listing)
     */
    public function index(): JsonResponse
    {
        $tags = Tag::orderBy('name')->get();

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
     * Get featured/popular tags (for homepage)
     */
    public function featured(Request $request): JsonResponse
    {
        $limit = min($request->input('limit', 15), 30);

        $tags = Tag::withCount('tattoos')
                   ->orderBy('tattoos_count', 'desc')
                   ->limit($limit)
                   ->get();

        return response()->json([
            'success' => true,
            'data' => $tags
        ]);
    }

    /**
     * Get a single tag by slug
     */
    public function show(string $slug): JsonResponse
    {
        $tag = Tag::where('slug', $slug)->first();

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
}
