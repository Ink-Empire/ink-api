<?php

namespace App\Http\Controllers;

use App\Enums\ArtistTattooApprovalStatus;
use App\Enums\UserTypes;
use App\Http\Resources\Elastic\TattooResource;
use App\Http\Resources\PendingTattooResource;
use App\Jobs\GenerateAiTagsJob;
use App\Jobs\IndexTattooJob;
use App\Models\Artist;
use App\Models\Image;
use App\Models\Studio;
use App\Models\Style;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\User;
use App\Models\ArtistInvitation;
use App\Notifications\ArtistTaggedNotification;
use App\Notifications\TattooApprovedNotification;
use App\Notifications\TattooRejectedNotification;
use App\Services\ImageService;
use App\Services\TattooService;
use App\Services\TagService;
use App\Services\UserService;
use App\Services\GooglePlacesService;
use App\Services\SearchImpressionService;
use App\Services\PaginationService;
use App\Util\ParseInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TattooController extends Controller
{
    private $filters = [];
    private $search;

    public const RELATIONSHIPS = [
        'styles' => Style::class,
    ];

    public function __construct(
        protected TattooService $tattooService,
        protected ImageService $imageService,
        protected UserService $userService,
        protected TagService $tattooTagService,
        protected GooglePlacesService $googlePlacesService,
        protected SearchImpressionService $impressionService,
        protected PaginationService $paginationService
    ) {
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
    public function getById(Request $request, $id): JsonResponse
    {
        $cacheKey = "es:tattoo:{$id}";
        $tattoo = Cache::remember($cacheKey, 600, function () use ($id) {
            return $this->tattooService->getById($id);
        });

        // Fallback to DB for tattoos not in ES (pending/user_only)
        if (!$tattoo) {
            $dbTattoo = Tattoo::with(['primary_image', 'images', 'styles', 'artist', 'studio', 'primary_style', 'tags', 'uploader'])
                ->find($id);

            if ($dbTattoo) {
                return $this->returnResponse('tattoo', new TattooResource($dbTattoo));
            }

            return $this->returnErrorResponse('Tattoo not found', 'Tattoo not found', 404);
        }

        // Check if blocked - tattoo from Elasticsearch is an array
        $user = $request->user();
        if ($user && $tattoo) {
            $artistId = is_array($tattoo) ? ($tattoo['artist_id'] ?? null) : ($tattoo->artist_id ?? null);
            if ($artistId && $user->isBlocked($artistId)) {
                return response()->json(['error' => 'Tattoo not found'], 404);
            }
        }

        return $this->returnResponse('tattoo', $tattoo);
    }

    public function search(Request $request): JsonResponse
    {
        $params = $request->all();
        $pagination = $this->paginationService->extractParams($params);

        $isDemo = $request->user()?->is_demo ? true : false;
        $params['is_demo'] = $isDemo;
        $cacheKey = 'es:tattoos:search:' . md5(($isDemo ? '1' : '0') . json_encode($params));
        $response = Cache::remember($cacheKey, 120, function () use ($params) {
            $parentSpan = \Sentry\SentrySdk::getCurrentHub()->getSpan();
            $esSpan = $parentSpan?->startChild(
                \Sentry\Tracing\SpanContext::make()->setOp('es.search')->setDescription('Tattoo ES search')
            );

            $response = $this->tattooService->search($params);

            $esSpan?->finish();

            return $response;
        });

        if (count($response["response"]) == 0) {
            $response['none_found'] = "No results found for your search, here are some suggestions: \n" .
                "1. Try searching for a different tattoo style or artist.\n" .
                "2. Check your spelling and try again.\n" .
                "3. Broaden your search radius to find more results.";
        }

        $total = $response['total'] ?? 0;
        $paginationMeta = $this->paginationService->buildMeta($total, $pagination['page'], $pagination['per_page']);

        return response()->json([
            'response' => $response['response'],
            ...$paginationMeta,
            'none_found' => $response['none_found'] ?? null,
        ]);
    }

    /**
     * Standalone endpoint for fetching unclaimed studios asynchronously.
     * Called from frontend in parallel with search results.
     */
    public function unclaimedStudios(Request $request): JsonResponse
    {
        $params = $request->all();

        // Don't hit Google Places API when viewing demo data
        if (!empty($params['is_demo'])) {
            return response()->json(['unclaimed_studios' => []]);
        }

        $locationCoords = $params['locationCoords'] ?? null;
        $useAnyLocation = $params['useAnyLocation'] ?? false;

        // Don't search Google Places if no location or searching "Anywhere"
        if (!$locationCoords || $useAnyLocation) {
            return response()->json(['unclaimed_studios' => []]);
        }

        $distance = $params['distance'] ?? 25;
        $distanceUnit = $params['distanceUnit'] ?? 'mi';

        // Convert to meters for Google Places API
        $radiusMeters = $distanceUnit === 'km'
            ? $distance * 1000
            : $distance * 1609.34;

        $unclaimedStudios = $this->googlePlacesService->searchTattooParlors(
            $locationCoords,
            (int) min($radiusMeters, 50000), // Max 50km for Google Places
            5 // Limit to 5 unclaimed studios
        );

        // Record impressions for unclaimed studios
        if (!empty($unclaimedStudios)) {
            $unclaimedIds = collect($unclaimedStudios)->pluck('id')->toArray();
            $this->impressionService->recordStudioImpressions(
                $unclaimedIds,
                $params['location'] ?? null,
                $locationCoords,
                $params,
                $request->ip()
            );

            // Add weekly impression counts to each studio
            foreach ($unclaimedStudios as $studio) {
                $studio->weekly_impressions = $this->impressionService->getWeeklyImpressionCount($studio->id);
            }
        }

        return response()->json(['unclaimed_studios' => $unclaimedStudios]);
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

            IndexTattooJob::dispatch($tattoo->id);

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

            if (!$user) {
                return $this->returnErrorResponse("Unauthorized", "You must be logged in to create a tattoo", 401);
            }

            $images = [];

            // Check for pre-uploaded image IDs (from presigned URL flow)
            $imageIds = $request->input('image_ids');
            if (!empty($imageIds)) {
                if (is_string($imageIds)) {
                    $imageIds = json_decode($imageIds, true);
                }
                if (is_array($imageIds) && !empty($imageIds)) {
                    // Fetch the Image records
                    $images = Image::whereIn('id', $imageIds)->get()->all();
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

            if (count($images) > 0) {
                $primaryImage = $images[0];

                $styleIds = $request->has('style_ids')
                    ? ParseInput::ids($request->input('style_ids'))
                    : [];

                // Get primary style from explicit field or first style
                $primaryStyleId = $request->input('primary_style_id')
                    ?: (!empty($styleIds) ? $styleIds[0] : null);

                $studioId = $request->input('studio_id');
                if ($studioId && !Studio::where('id', $studioId)->exists()) {
                    $studioId = null;
                }

                $tattoo = $this->tattooService->createTattoo($user, [
                    'tagged_artist_id' => $request->input('tagged_artist_id'),
                    'primary_image_id' => $primaryImage->id,
                    'title' => $request->input('title'),
                    'description' => $request->input('description'),
                    'placement' => $request->input('placement'),
                    'duration' => $request->input('hours_to_complete'),
                    'primary_style_id' => $primaryStyleId,
                    'studio_id' => $studioId,
                    'attributed_artist_name' => $request->input('attributed_artist_name'),
                    'attributed_studio_name' => $request->input('attributed_studio_name'),
                    'attributed_location' => $request->input('attributed_location'),
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

                // Notify tagged artist if this is a client upload
                if ($tattoo->approval_status === ArtistTattooApprovalStatus::PENDING && $tattoo->artist_id) {
                    $taggedArtist = User::find($tattoo->artist_id);
                    if ($taggedArtist) {
                        $taggedArtist->notify(new ArtistTaggedNotification($tattoo, $user));
                    }
                }

                // Create artist invitation if attributed artist + email or phone provided
                $artistInviteEmail = $request->input('artist_invite_email');
                $artistInvitePhone = $request->input('artist_invite_phone');
                $attributedArtistName = $request->input('attributed_artist_name');
                if ($attributedArtistName && ($artistInviteEmail || $artistInvitePhone)) {
                    $invitation = ArtistInvitation::create([
                        'tattoo_id' => $tattoo->id,
                        'invited_by_user_id' => $user->id,
                        'artist_name' => $attributedArtistName,
                        'studio_name' => $request->input('attributed_studio_name'),
                        'location' => $request->input('attributed_location'),
                        'location_lat_long' => $request->input('attributed_location_lat_long'),
                        'email' => $artistInviteEmail ?? '',
                    ]);

                    if ($artistInviteEmail) {
                        \Illuminate\Support\Facades\Notification::route('mail', $artistInviteEmail)
                            ->notify(new \App\Notifications\ArtistInvitationNotification($invitation, $user));
                    }
                }

                // Dispatch background jobs for AI tags and ES indexing
                GenerateAiTagsJob::dispatch($tattoo->id, $userSelectedTagIds);
                IndexTattooJob::dispatch($tattoo->id);
                Cache::forget("es:tattoo:{$tattoo->id}");
                $this->bustUserTattooCache($user->id);

                $tattoo->refresh();
                $tattoo->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image']);

                $responseData = [
                    'tattoo' => new TattooResource($tattoo),
                    'ai_tags_pending' => true,
                ];

                if (isset($invitation)) {
                    $responseData['invitation_token'] = $invitation->token;
                }

                return response()->json($responseData);
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
            $tattoo = Tattoo::find($id);

            if (!$tattoo) {
                return $this->returnErrorResponse('Tattoo not found');
            }

            // Verify the user owns this tattoo
            if ($tattoo->artist_id !== $user->id && $tattoo->uploaded_by_user_id !== $user->id) {
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

            // Handle image_ids from pre-uploaded images (S3 upload flow)
            $imageIds = $request->input('image_ids');
            if (!empty($imageIds) && is_array($imageIds)) {
                $imageIds = array_filter($imageIds);
                if (count($imageIds) > 0) {
                    $tattoo->images()->sync($imageIds);

                    // Update primary image if current one was removed
                    if (!in_array($tattoo->primary_image_id, $imageIds)) {
                        $tattoo->primary_image_id = $imageIds[0];
                    }

                    \Log::info("Synced images on tattoo", [
                        'tattoo_id' => $tattoo->id,
                        'image_ids' => $imageIds,
                    ]);
                }
            }

            // Handle new image uploads (multipart file upload flow)
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

            // Handle styles - accepts array, JSON string, or comma-separated string
            $styles = $request->input('styles');
            if (!empty($styles)) {
                $styleIds = ParseInput::ids($styles);
                if (!empty($styleIds)) {
                    $tattoo->styles()->sync($styleIds);
                }
            }

            // Handle tags - accepts array, JSON string, or comma-separated string
            $tagIds = $request->input('tag_ids');
            if (!empty($tagIds)) {
                $tagIds = ParseInput::ids($tagIds);
                if (!empty($tagIds)) {
                    $tattoo->tags()->sync($tagIds);
                }
            }

            \Log::info("updated tattoo", ['tattoo' => $tattoo->id]);

            IndexTattooJob::dispatch($tattoo->id);
            Cache::forget("es:tattoo:{$tattoo->id}");
            $this->bustUserTattooCache($tattoo->uploaded_by_user_id);

            $tattoo->refresh();
            $tattoo->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image']);

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
            $tattoo = Tattoo::find($id);

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

            IndexTattooJob::dispatch($tattoo->id);

            $tattoo->refresh();
            $tattoo->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image']);

            return $this->returnResponse('tattoo', new TattooResource($tattoo));

        } catch (\Exception $e) {
            \Log::error("Unable to update tattoo featured status", [
                'error' => $e->getMessage(),
                'tattoo_id' => $id
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * Delete a tattoo and its associated images from S3.
     * Only the tattoo owner can delete their own tattoos.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            $tattoo = Tattoo::with('images')->find($id);

            if (!$tattoo) {
                return $this->returnErrorResponse('Tattoo not found', 404);
            }

            if ($tattoo->artist_id !== $user->id && $tattoo->uploaded_by_user_id !== $user->id) {
                return $this->returnErrorResponse('You can only delete your own tattoos', 403);
            }

            \Log::info("Deleting tattoo", ['tattoo_id' => $id, 'user_id' => $user->id]);

            $this->bustUserTattooCache($tattoo->uploaded_by_user_id);
            $deletedImageCount = $this->tattooService->deleteTattoo($tattoo);

            \Log::info("Tattoo deleted successfully", [
                'tattoo_id' => $id,
                'images_deleted' => $deletedImageCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tattoo deleted successfully',
                'images_deleted' => $deletedImageCount,
            ]);

        } catch (\Exception $e) {
            \Log::error("Unable to delete tattoo", [
                'error' => $e->getMessage(),
                'tattoo_id' => $id,
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * Bulk delete tattoos. Only the owner can delete their own tattoos.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $ids = $request->input('ids', []);

            if (!is_array($ids) || count($ids) === 0) {
                return $this->returnErrorResponse('No tattoo IDs provided', 422);
            }

            if (count($ids) > 50) {
                return $this->returnErrorResponse('Cannot delete more than 50 tattoos at once', 422);
            }

            $tattoos = Tattoo::with('images')->whereIn('id', $ids)->get();
            $deleted = 0;
            $failed = [];

            foreach ($tattoos as $tattoo) {
                if ($tattoo->artist_id !== $user->id && $tattoo->uploaded_by_user_id !== $user->id) {
                    $failed[] = $tattoo->id;
                    continue;
                }

                $this->bustUserTattooCache($tattoo->uploaded_by_user_id);
                $this->tattooService->deleteTattoo($tattoo);
                $deleted++;
            }

            return response()->json([
                'success' => true,
                'deleted_count' => $deleted,
                'failed_ids' => $failed,
            ]);

        } catch (\Exception $e) {
            \Log::error("Bulk delete failed", ['error' => $e->getMessage()]);
            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * Admin: Delete a tattoo, removing from DB, Elasticsearch, and S3.
     */
    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        try {
            $tattoo = Tattoo::with('images')->find($id);

            if (!$tattoo) {
                return response()->json(['message' => 'Tattoo not found'], 404);
            }

            \Log::info("Admin deleting tattoo", ['tattoo_id' => $id, 'admin_id' => $request->user()->id]);

            $this->bustUserTattooCache($tattoo->uploaded_by_user_id);
            $deletedImageCount = $this->tattooService->deleteTattoo($tattoo);

            return response()->json([
                'data' => ['id' => $id],
                'message' => 'Tattoo deleted successfully',
                'images_deleted' => $deletedImageCount,
            ]);

        } catch (\Exception $e) {
            \Log::error("Admin unable to delete tattoo", [
                'error' => $e->getMessage(),
                'tattoo_id' => $id,
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get pending tattoo approvals for the authenticated artist.
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->type_id !== UserTypes::ARTIST_TYPE_ID) {
            return $this->returnErrorResponse('Only artists can view pending approvals', 403);
        }

        // Tattoos where artist was tagged via search (artist_id set, pending approval)
        $taggedTattoos = Tattoo::with(['primary_image', 'images', 'styles', 'uploader', 'uploader.image'])
            ->pendingForArtist($user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Tattoos from unclaimed email invitations matching this artist's email
        $invitedTattooIds = ArtistInvitation::where('email', $user->email)
            ->whereNull('claimed_at')
            ->pluck('tattoo_id');

        $invitedTattoos = collect();
        if ($invitedTattooIds->isNotEmpty()) {
            $invitedTattoos = Tattoo::with(['primary_image', 'images', 'styles', 'uploader', 'uploader.image'])
                ->whereIn('id', $invitedTattooIds)
                ->whereNull('artist_id')
                ->orderBy('created_at', 'desc')
                ->get();
        }

        $allTattoos = $taggedTattoos->merge($invitedTattoos)->unique('id');

        return $this->returnResponse('tattoos', PendingTattooResource::collection($allTattoos));
    }

    /**
     * Approve or reject a tattoo tag request.
     */
    public function respondToTagRequest(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $tattoo = Tattoo::with(['uploader'])->find($id);

        if (!$tattoo) {
            return $this->returnErrorResponse('Tattoo not found', 404);
        }

        // Check authorization: either tagged directly OR has matching email invitation
        $isTaggedArtist = $tattoo->artist_id === $user->id;
        $invitation = null;

        if (!$isTaggedArtist) {
            $invitation = ArtistInvitation::where('tattoo_id', $tattoo->id)
                ->where('email', $user->email)
                ->whereNull('claimed_at')
                ->first();

            if (!$invitation) {
                return $this->returnErrorResponse('You can only respond to tattoos tagged to you', 403);
            }
        }

        // For tagged artists, check pending status; for invitations, tattoo has no approval_status set
        if ($isTaggedArtist && $tattoo->approval_status !== ArtistTattooApprovalStatus::PENDING) {
            return $this->returnErrorResponse('This tattoo is not pending approval');
        }

        $action = $request->input('action');

        if ($action === 'approve') {
            $tattoo->artist_id = $user->id;
            $tattoo->approval_status = ArtistTattooApprovalStatus::APPROVED;
            $tattoo->is_visible = true;
            $tattoo->attributed_artist_name = null;
            $tattoo->attributed_studio_name = null;
            $tattoo->attributed_location = null;
            $tattoo->save();

            // Mark invitation as claimed
            if ($invitation) {
                $invitation->claimed_by_user_id = $user->id;
                $invitation->claimed_at = now();
                $invitation->save();
            }

            $tattoo->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image']);
            $tattoo->searchable();
            Cache::forget("es:tattoo:{$tattoo->id}");
            IndexTattooJob::bustArtistCaches($user->id, $user->slug);

            if ($tattoo->uploader) {
                try {
                    $tattoo->uploader->notify(new TattooApprovedNotification($tattoo, $user));
                } catch (\Throwable $e) {
                    \Log::warning("Failed to send approval notification for tattoo {$tattoo->id}: " . $e->getMessage());
                }
                $this->bustUserTattooCache($tattoo->uploader->id);
            }

            return response()->json(['success' => true, 'message' => 'Tattoo approved']);
        } elseif ($action === 'reject') {
            $tattoo->artist_id = null;
            $tattoo->attributed_artist_name = null;
            $tattoo->attributed_studio_name = null;
            $tattoo->attributed_location = null;
            $tattoo->approval_status = ArtistTattooApprovalStatus::USER_ONLY;
            $tattoo->is_visible = false;
            $tattoo->save();

            // Mark invitation as claimed (declined)
            if ($invitation) {
                $invitation->claimed_by_user_id = $user->id;
                $invitation->claimed_at = now();
                $invitation->save();
            }

            $tattoo->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image']);
            $tattoo->searchable();
            Cache::forget("es:tattoo:{$tattoo->id}");
            IndexTattooJob::bustArtistCaches($user->id, $user->slug);

            if ($tattoo->uploader) {
                try {
                    $tattoo->uploader->notify(new TattooRejectedNotification($tattoo, $user));
                } catch (\Throwable $e) {
                    \Log::warning("Failed to send rejection notification for tattoo {$tattoo->id}: " . $e->getMessage());
                }
                $this->bustUserTattooCache($tattoo->uploader->id);
            }

            return response()->json(['success' => true, 'message' => 'Tag rejected']);
        }

        return $this->returnErrorResponse('Invalid action. Use "approve" or "reject".');
    }

    private function bustUserTattooCache(?int $userId): void
    {
        if ($userId) {
            Cache::increment("es:user:{$userId}:tattoos:version");
        }
    }

    /**
     * Admin: Get a single tattoo
     */
    public function adminShow(int $id): JsonResponse
    {
        $tattoo = Tattoo::with(['artist', 'primary_image', 'tags', 'primary_style', 'styles'])->find($id);

        if (!$tattoo) {
            return response()->json(['message' => 'Tattoo not found'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $tattoo->id,
                'title' => $tattoo->title,
                'description' => $tattoo->description,
                'placement' => $tattoo->placement,
                'artist_id' => $tattoo->artist_id,
                'artist_name' => $tattoo->artist?->name,
                'primary_image' => $tattoo->primary_image?->uri,
                'tags' => $tattoo->tags->pluck('name')->toArray(),
                'primary_style' => $tattoo->primary_style?->name,
                'styles' => $tattoo->styles->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->toArray(),
                'style_ids' => $tattoo->styles->pluck('id')->toArray(),
                'created_at' => $tattoo->created_at,
            ],
        ]);
    }

    /**
     * Admin: Update a tattoo (standard React Admin update)
     */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $tattoo = Tattoo::find($id);

        if (!$tattoo) {
            return response()->json(['message' => 'Tattoo not found'], 404);
        }

        // Update description
        if ($request->has('description')) {
            $tattoo->description = $request->input('description');
        }

        if ($request->has('placement')) {
            $tattoo->placement = $request->input('placement');
            ;
            $tattoo->save();
        }

        if ($request->has('title')) {
            $tattoo->title = $request->input('title');
        }

        $tattoo->save();

        // Handle tags if provided
        if ($request->has('tag_names')) {
            $tagNamesInput = $request->input('tag_names', '');
            $tagNames = array_filter(
                array_map('trim', explode(',', $tagNamesInput)),
                fn ($name) => strlen($name) >= 2
            );

            $allTags = collect();

            foreach ($tagNames as $tagName) {
                $tagName = strtolower(trim($tagName));
                $slug = \Illuminate\Support\Str::slug($tagName);

                $tag = Tag::where('name', $tagName)
                    ->orWhere('slug', $slug)
                    ->first();

                if ($tag) {
                    $allTags->push($tag);
                } else {
                    $newTag = Tag::create([
                        'name' => $tagName,
                        'slug' => $slug,
                        'is_pending' => false,
                        'is_ai_generated' => false,
                    ]);
                    $allTags->push($newTag);
                }
            }

            $tattoo->tags()->sync($allTags->pluck('id')->toArray());
        }

        // Handle styles if provided
        if ($request->has('style_ids')) {
            $styleIds = ParseInput::ids($request->input('style_ids'));
            if (!empty($styleIds)) {
                $tattoo->styles()->sync($styleIds);
            }
        }

        IndexTattooJob::dispatch($tattoo->id);

        $tattoo->refresh();
        $tattoo->load('styles');

        return response()->json([
            'data' => [
                'id' => $tattoo->id,
                'title' => $tattoo->title,
                'description' => $tattoo->description,
                'placement' => $tattoo->placement,
                'artist_id' => $tattoo->artist_id,
                'artist_name' => $tattoo->artist?->name,
                'primary_image' => $tattoo->primary_image?->uri,
                'tags' => $tattoo->tags->pluck('name')->toArray(),
                'primary_style' => $tattoo->primary_style?->name,
                'styles' => $tattoo->styles->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->toArray(),
                'style_ids' => $tattoo->styles->pluck('id')->toArray(),
                'created_at' => $tattoo->created_at,
            ],
        ]);
    }

    /**
     * Admin: List all tattoos with pagination
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $pagination = $this->paginationService->extractParams($request);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'desc');
        $filter = $request->input('filter', []);

        $query = Tattoo::with(['artist', 'primary_image', 'tags', 'primary_style', 'styles']);

        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?? [];
        }

        // Search filter
        if (!empty($filter['q'])) {
            $search = $filter['q'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Artist filter
        if (!empty($filter['artist_id'])) {
            $query->where('artist_id', $filter['artist_id']);
        }

        // Has tags filter
        if (isset($filter['has_tags'])) {
            if ($filter['has_tags']) {
                $query->whereHas('tags');
            } else {
                $query->whereDoesntHave('tags');
            }
        }

        // Has description filter
        if (isset($filter['has_description'])) {
            if ($filter['has_description']) {
                $query->whereNotNull('description')->where('description', '!=', '');
            } else {
                $query->where(function ($q) {
                    $q->whereNull('description')->orWhere('description', '');
                });
            }
        }

        $query->orderBy($sort, $order);

        $total = $query->count();
        $tattoos = $this->paginationService->applyToQuery($query, $pagination['offset'], $pagination['per_page'])->get();

        return response()->json([
            'data' => $tattoos->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'artist_id' => $t->artist_id,
                'artist_name' => $t->artist?->name,
                'primary_image' => $t->primary_image?->uri,
                'tags' => $t->tags->pluck('name')->toArray(),
                'primary_style' => $t->primary_style?->name,
                'styles' => $t->styles->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->toArray(),
                'created_at' => $t->created_at,
            ]),
            'total' => $total,
        ]);
    }
}
