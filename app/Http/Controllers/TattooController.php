<?php

namespace App\Http\Controllers;

use App\Http\Requests\TattooCreateRequest;
use App\Http\Resources\Elastic\TattooResource;
use App\Jobs\GenerateAiTagsJob;
use App\Models\Artist;
use App\Models\Image;
use App\Models\Style;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\User;
use App\Services\ImageService;
use App\Services\TattooService;
use App\Services\TagService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TattooController extends Controller
{
    private $filters = [];
    private $search;

    const RELATIONSHIPS = [
        'styles' => Style::class,
    ];

    public function __construct(
        protected TattooService $tattooService,
        protected ImageService  $imageService,
        protected UserService   $userService,
        protected TagService $tattooTagService
    )
    {
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get()
    {
        //eventually perhaps replaced with an ES call
        $tattoos = $this->tattooService->get();

        return $this->returnResponse('tattoos', TattooResource::collection($tattoos));
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id): JsonResponse
    {
        $tattoo = $this->tattooService->getById($id);

        return $this->returnResponse('tattoo', $tattoo);
    }

    public function search(Request $request): JsonResponse
    {
        $params = $request->all();

        $response = $this->tattooService->search($params);

        //if response.items is empty, re-do search without distance filters and return an error message
        if (count($response["response"]) == 0) {
            $response = $this->tattooService->search($params);
            $response['none_found'] = "No results found for your search, here are some suggestions: \n" .
                "1. Try searching for a different tattoo style or artist.\n" .
                "2. Check your spelling and try again.\n" .
                "3. Broaden your search radius to find more results.";
        }

        // Add total count if not already present
        if (!isset($response['total'])) {
            $response['total'] = count($response['response']);
        }

        return $this->returnElasticResponse($response);
    }

    /**
     * Generate AI tags for a specific tattoo
     */
    public function generateTags(Request $request, $id): JsonResponse
    {
        try {
            $tattoo = Tattoo::with(['images', 'tags'])->find($id);

            if (!$tattoo) {
                return $this->returnErrorResponse('Tattoo not found', 'The specified tattoo does not exist.');
            }

            if ($tattoo->images->count() === 0) {
                return $this->returnErrorResponse('No images', 'This tattoo has no images to analyze.');
            }

            // Check if user owns this tattoo or is admin
            $user = $request->user();
            if ($user->id !== $tattoo->artist_id && $user->type_id !== 1) { // Assuming type_id 1 is admin
                return $this->returnErrorResponse('Unauthorized', 'You can only generate tags for your own tattoos.');
            }

            $regenerate = $request->boolean('regenerate', false);

            $tags = $regenerate
                ? $this->tattooTagService->regenerateTagsForTattoo($tattoo)
                : $this->tattooTagService->generateTagsForTattoo($tattoo);

            if (count($tags) === 0 && !$regenerate && $tattoo->tags->count() > 0) {
                return $this->returnResponse('tags', [
                    'message' => 'Tattoo already has tags. Use regenerate=true to create new ones.',
                    'existing_tags' => $tattoo->tags->pluck('tag')->toArray()
                ]);
            }

            // Re-index the tattoo for search
            $tattoo->searchable();

            return $this->returnResponse('tags', [
                'message' => 'Tags generated successfully',
                'tags' => collect($tags)->pluck('tag')->toArray(),
                'count' => count($tags)
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate tags for tattoo', [
                'tattoo_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->returnErrorResponse('Tag generation failed', $e->getMessage());
        }
    }

    public function create(Request $request): JsonResponse|TattooResource
    {
        try {
            $user = $request->user();

            $images = [];

            // Check for pre-uploaded image IDs (from presigned URL flow)
            $imageIds = $request->input('image_ids');
            if (!empty($imageIds)) {
                if (is_string($imageIds)) {
                    $imageIds = json_decode($imageIds, true);
                }
                if (is_array($imageIds) && !empty($imageIds)) {
                    // Fetch the Image records
                    $images = \App\Models\Image::whereIn('id', $imageIds)->get()->all();
                    \Log::info("Using pre-uploaded images", [
                        'image_ids' => $imageIds,
                        'found' => count($images)
                    ]);
                }
            }

            // Fall back to traditional file upload if no image_ids provided
            if (empty($images)) {
                $files = $request->file('files');

                if (empty($files)) {
                    return $this->returnErrorResponse("No images uploaded", "Please select at least one image");
                }

                if (!is_array($files)) {
                    $files = [$files];
                }

                $files = array_filter($files);

                if (empty($files)) {
                    return $this->returnErrorResponse("No valid images", "Please select at least one valid image");
                }

                $images = $this->tattooService->upload($files, $user);
            }

            if(count($images) > 0) {
               $primaryImage = $images[0];

                // Handle style IDs
                $styleIds = [];
                if ($request->has('style_ids')) {
                    $styleIds = json_decode($request->input('style_ids'), true);
                    if (!is_array($styleIds)) {
                        $styleIds = [];
                    }
                }

                // Get primary style from explicit field or first style
                $primaryStyleId = $request->input('primary_style_id')
                    ?: (!empty($styleIds) ? $styleIds[0] : null);

                $tattoo = Tattoo::create([
                    'artist_id' => $user->id,
                    'primary_image_id' => $primaryImage->id,
                    'studio_id' => $user->studio_id ?? null,
                    'title' => $request->input('title'),
                    'description' => $request->input('description'),
                    'placement' => $request->input('placement'),
                    'duration' => $request->input('hours_to_complete'),
                    'primary_style_id' => $primaryStyleId,
                ]);

                // Attach ALL images to the pivot table (including the primary image)
                $imageIds = collect($images)->pluck('id')->toArray();

                // Ensure primary image is definitely included in the pivot table
                if (!in_array($primaryImage->id, $imageIds)) {
                    $imageIds[] = $primaryImage->id;
                }

                $tattoo->images()->attach($imageIds);

                // Attach all selected styles to the tattoo
                if (!empty($styleIds)) {
                    $tattoo->styles()->attach($styleIds);
                }

                // Handle user-selected tags (before AI generation)
                // These are tags the user explicitly chose during upload
                $userSelectedTagIds = [];
                if ($request->has('tag_ids')) {
                    $userTagIds = json_decode($request->input('tag_ids'), true);
                    if (is_array($userTagIds) && !empty($userTagIds)) {
                        // Validate that tag IDs exist
                        $userSelectedTagIds = Tag::whereIn('id', $userTagIds)->pluck('id')->toArray();
                        if (!empty($userSelectedTagIds)) {
                            $tattoo->tags()->attach($userSelectedTagIds);
                            \Log::info("User-selected tags attached to tattoo", [
                                'tattoo_id' => $tattoo->id,
                                'tag_ids' => $userSelectedTagIds
                            ]);
                        }
                    }
                }

                // Dispatch AI tag generation to background job for async processing
                GenerateAiTagsJob::dispatch($tattoo->id, $userSelectedTagIds);
                \Log::info("Dispatched GenerateAiTagsJob for tattoo", ['tattoo_id' => $tattoo->id]);

                // Refresh and index the tattoo (will be async with SCOUT_QUEUE=true)
                $tattoo->refresh();
                $tattoo->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style']);
                $tattoo->searchable();
                Artist::find($user->id)?->searchable();

                // Return immediately - AI suggestions will be generated in background
                // Frontend can poll /tattoos/{id} or use websockets to get updated tags
                return response()->json([
                    'tattoo' => new TattooResource($tattoo),
                    'ai_suggested_tags' => [], // Tags generated async, will be available shortly
                    'ai_tags_pending' => true  // Signal to frontend that AI tags are being generated
                ]);
            } else {
                return $this->returnErrorResponse("No images uploaded", "No files uploaded");
            }

        } catch (\Exception $e) {
            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            $tattoo = $this->tattooService->getById($id);

            if (!$tattoo) {
                return $this->returnErrorResponse('Tattoo not found');
            }

            // Verify the user owns this tattoo
            if ($tattoo->artist_id !== $user->id) {
                return $this->returnErrorResponse('You can only update your own tattoos', 403);
            }

            // Update basic fields
            if ($request->has('title')) {
                $tattoo->title = $request->input('title');
            }
            if ($request->has('description')) {
                $tattoo->description = $request->input('description');
            }
            if ($request->has('placement')) {
                $tattoo->placement = $request->input('placement');
            }
            if ($request->has('duration')) {
                $tattoo->duration = $request->input('duration');
            }

            // Handle image deletions
            $deletedImageIds = $request->input('deleted_image_ids');
            if (!empty($deletedImageIds)) {
                if (is_string($deletedImageIds)) {
                    $deletedImageIds = json_decode($deletedImageIds, true);
                }
                if (is_array($deletedImageIds) && count($deletedImageIds) > 0) {
                    // Detach deleted images from the pivot table
                    $tattoo->images()->detach($deletedImageIds);

                    // If primary image was deleted, set a new one
                    if (in_array($tattoo->primary_image_id, $deletedImageIds)) {
                        $remainingImage = $tattoo->images()->first();
                        $tattoo->primary_image_id = $remainingImage ? $remainingImage->id : null;
                    }

                    \Log::info("Deleted images from tattoo", [
                        'tattoo_id' => $tattoo->id,
                        'deleted_ids' => $deletedImageIds
                    ]);
                }
            }

            // Handle new image uploads
            $files = $request->file('files');
            if (!empty($files)) {
                if (!is_array($files)) {
                    $files = [$files];
                }
                $files = array_filter($files);

                if (count($files) > 0) {
                    $newImages = $this->tattooService->upload($files, $user);
                    $newImageIds = collect($newImages)->pluck('id')->toArray();
                    $tattoo->images()->attach($newImageIds);

                    // If no primary image, set the first new one
                    if (!$tattoo->primary_image_id && count($newImages) > 0) {
                        $tattoo->primary_image_id = $newImages[0]->id;
                    }

                    \Log::info("Added new images to tattoo", [
                        'tattoo_id' => $tattoo->id,
                        'new_image_ids' => $newImageIds
                    ]);
                }
            }

            $tattoo->save();

            // Handle styles - can be array of IDs or comma-separated string
            $styles = $request->input('styles');
            if (!empty($styles)) {
                if (is_array($styles)) {
                    $styleIds = $styles;
                } else {
                    $styleIds = explode(",", $styles);
                }
                $tattoo->styles()->sync($styleIds);
            }

            // Handle tags
            $tagIds = $request->input('tag_ids');
            if (!empty($tagIds)) {
                if (is_array($tagIds)) {
                    $tattoo->tags()->sync($tagIds);
                }
            }

            \Log::info("updated tattoo", ['tattoo' => $tattoo->id]);

            // Refresh to ensure all relationships are loaded before indexing
            $tattoo->refresh();
            $tattoo->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image']);
            $tattoo->searchable();

            \Log::info("indexed tattoo", ['tattoo' => $tattoo->id]);

            return $this->returnResponse('tattoo', new TattooResource($tattoo));

        } catch (\Exception $e) {
            \Log::error("Unable to update tattoo", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    public function toggleFeatured(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            $tattoo = $this->tattooService->getById($id);

            if (!$tattoo) {
                return $this->returnErrorResponse('Tattoo not found');
            }

            // Verify the user owns this tattoo
            if ($tattoo->artist_id !== $user->id) {
                return $this->returnErrorResponse('You can only update your own tattoos', 403);
            }

            // Toggle or set featured status
            $featured = $request->has('is_featured')
                ? (bool) $request->input('is_featured')
                : !$tattoo->is_featured;

            $tattoo->is_featured = $featured;
            $tattoo->save();

            // Re-index for search
            $tattoo->searchable();

            return $this->returnResponse('tattoo', new TattooResource($tattoo));

        } catch (\Exception $e) {
            \Log::error("Unable to update tattoo featured status", [
                'error' => $e->getMessage(),
                'tattoo_id' => $id
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }
}
