<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\DashboardArtistResource;
use App\Http\Resources\StudioArtistResource;
use App\Http\Resources\StudioDashboardResource;
use App\Http\Resources\StudioResource;
use App\Http\Resources\StudioStatsResource;
use App\Http\Resources\StudioWorkingHoursResource;
use App\Http\Resources\UserResource;
use App\Models\StudioAvailability;
use App\Models\Studio;
use App\Models\StudioAnnouncement;
use App\Models\StudioSpotlight;
use App\Models\User;
use App\Services\AddressService;
use App\Services\ImageService;
use App\Services\StudioService;
use App\Services\UserService;
use App\Services\GooglePlacesService;
use App\Services\PaginationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioController extends Controller
{
    public function __construct(
        protected AddressService $addressService,
        protected ImageService $imageService,
        protected StudioService $studioService,
        protected UserService $userService,
        protected GooglePlacesService $googlePlacesService,
        protected PaginationService $paginationService
    ) {
    }

    /**
     * Check if a studio username or email is available
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $username = $request->input('username');

        if ($email) {
            $emailExists = Studio::where('email', $email)->exists();
            return response()->json([
                'available' => !$emailExists,
                'field' => 'email'
            ]);
        }

        if ($username) {
            // Check both username and slug since they're often the same
            $usernameExists = Studio::where('slug', $username)
                ->orWhere('slug', strtolower($username))
                ->exists();
            return response()->json([
                'available' => !$usernameExists,
                'field' => 'username'
            ]);
        }

        return response()->json([
            'available' => true,
            'field' => null
        ]);
    }

    /**
     * Lookup a studio by Google Place ID, or create one if it doesn't exist.
     * Used by autocomplete to link artists to studios or pre-fill studio registration.
     */
    public function lookupOrCreate(Request $request): JsonResponse
    {
        $placeId = $request->input('place_id');

        if (!$placeId) {
            return response()->json(['error' => 'place_id is required'], 422);
        }

        // Check if we already have this studio
        $existingStudio = Studio::where('google_place_id', $placeId)->first();

        if ($existingStudio) {
            return response()->json([
                'studio' => $existingStudio,
                'is_new' => false,
                'is_claimed' => $existingStudio->is_claimed,
            ]);
        }

        // Fetch details from Google and create new unclaimed studio
        $studio = $this->googlePlacesService->createStudioFromPlaceId($placeId);

        if (!$studio) {
            return response()->json(['error' => 'Unable to fetch place details from Google'], 404);
        }

        return response()->json([
            'studio' => $studio,
            'is_new' => true,
            'is_claimed' => false,
        ]);
    }

    /**
     * Claim an existing unclaimed studio.
     * This is used when a studio owner signs up and selects their existing Google Places studio.
     */
    public function claim(Request $request, int $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (!$studio) {
            return response()->json(['error' => 'Studio not found'], 404);
        }

        if ($studio->is_claimed) {
            return response()->json(['error' => 'This studio has already been claimed'], 422);
        }

        $request->validate([
            'owner_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255',
            'about' => 'nullable|string',
            'location' => 'nullable|string',
            'location_lat_long' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        // Update the studio with the new owner's info and mark as claimed
        $studio->update([
            'owner_id' => $request->input('owner_id'),
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'about' => $request->input('about'),
            'location' => $request->input('location') ?: $studio->location,
            'location_lat_long' => $request->input('location_lat_long') ?: $studio->location_lat_long,
            'email' => $request->input('email'),
            'phone' => $request->input('phone') ?: $studio->phone,
            'is_claimed' => true,
        ]);

        return response()->json([
            'studio' => new StudioResource($studio->fresh()),
            'message' => 'Studio claimed successfully',
        ]);
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get()
    {
        $studios = $this->studioService->get();

        return $this->returnResponse('studios', StudioResource::collection($studios));
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id)
    {
        $studio = $this->studioService->getById($id);
        return $this->returnResponse('studio', new StudioResource($studio));
    }

    //TODO create custom request
    public function create(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $data = $request->all();

            $address = null;

//            if ($data['address']) {
//                $address = $this->addressService->create(
//                    [
//                        'address1' => $data['address']['address1'],
//                        'address2' => $data['address']['address2'] ?? null,
//                        'city' => $data['address']['city'],
//                        'state' => $data['address']['state'],
//                        'postal_code' => $data['address']['postal_code'],
//                        'country_code' => $data['address']['country_code'] ?? "US"
//                    ]
//                );
//            }

            $studio = new Studio([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
                'email' => $data['email'] ?? null,
                'about' => $data['about'] ?? null,
                'phone' => $data['phone'] ?? null,
                'location' => $data['location'] ?? null,
                'location_lat_long' => $data['location_lat_long'] ?? null,
                'address_id' => $address->id ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'is_claimed' => true, // Studios created via registration are claimed
            ]);

            $studio->save();

            return $this->returnResponse('studio', new StudioResource($studio));

        } catch (\Exception $e) {
            \Log::error("Unable to create studio", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();
        $studio = $this->studioService->getById($id);

        // Handle address fields - create or update Address record
        $addressFields = ['address', 'address2', 'city', 'state', 'postal_code'];
        $hasAddressData = collect($addressFields)->contains(fn($field) => isset($data[$field]) && $data[$field] !== '');

        if ($hasAddressData) {
            $addressData = [
                'address1' => $data['address'] ?? '',
                'address2' => $data['address2'] ?? null,
                'city' => $data['city'] ?? '',
                'state' => $data['state'] ?? '',
                'postal_code' => $data['postal_code'] ?? '',
                'country_code' => 'US',
            ];

            if ($studio->address_id && $studio->address) {
                // Update existing address
                $studio->address->update($addressData);
            } else {
                // Create new address
                $address = \App\Models\Address::create($addressData);
                $studio->address_id = $address->id;
            }
        }

        foreach ($data as $fieldName => $fieldVal) {
            // Skip address fields as they're handled above
            if (in_array($fieldName, $addressFields)) {
                continue;
            }

            if (in_array($fieldName, $studio->getFillable())) {
                $studio->{$fieldName} = $fieldVal;
            }

            switch ($fieldName) {
                case 'days':
                    $this->studioService->setBusinessDays($data, $studio);
                    $studio->load('business_hours');
                    break;
                case 'styles':
                    $this->studioService->updateStyles($studio, $fieldVal);
                    break;
                case 'tattoos':
                    $this->userService->updateTattoos($studio, $fieldVal);
                    break;
                case 'artists':
                    $this->userService->updateArtists($studio, $fieldVal);
                    break;
            }
        }
        $studio->save();

        // Reload the address relationship to include it in the response
        $studio->load('address');

        return $this->returnResponse('studio', new StudioResource($studio));

    }

    public function updateBusinessHours(Request $request, $id)
    {
        $data = $request->all();

        $studio = $this->studioService->getById($id);

        if (isset($data['days'])) {
            $this->studioService->setBusinessDays($data, $studio);
            $studio->load('business_hours');
        }

        return $this->returnResponse('studio', new StudioResource($studio));
    }

    /**
     * Get studio availability/working hours.
     */
    public function getAvailability(Request $request, $id)
    {
        $studio = $this->studioService->getById($id);

        if (!$studio) {
            return response()->json(['error' => 'Studio not found'], 404);
        }

        $availability = StudioAvailability::where('studio_id', $studio->id)->get();

        return StudioWorkingHoursResource::collection($availability);
    }

    /**
     * Set studio availability/working hours.
     */
    public function setAvailability(Request $request, $id)
    {
        $studio = $this->studioService->getById($id);

        if (!$studio) {
            return response()->json(['error' => 'Studio not found'], 404);
        }

        // Verify the current user owns this studio
        $user = $request->user();
        if (!$user || $studio->owner_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $availabilityArray = $request->get('availability');

        foreach ($availabilityArray as $availability) {
            StudioAvailability::updateOrCreate(
                [
                    'studio_id' => $studio->id,
                    'day_of_week' => $availability['day_of_week']
                ],
                [
                    'start_time' => $availability['start_time'],
                    'end_time' => $availability['end_time'],
                    'is_day_off' => $availability['is_day_off']
                ]
            );
        }

        return response()->json(['success' => true]);
    }

    /**
     * Upload or set studio image.
     * Accepts either:
     * - image_id: ID of an already-uploaded image (from presigned URL flow - faster)
     * - image: File upload or base64 string (legacy flow - slower)
     */
    public function uploadImage(Request $request, $id): JsonResponse
    {
        try {
            $studio = $this->studioService->getById($id);

            if (!$studio) {
                return $this->returnErrorResponse('Studio not found');
            }

            // Check for presigned URL flow (faster) - image already uploaded to S3
            if ($request->has('image_id')) {
                $image = \App\Models\Image::find($request->input('image_id'));

                if (!$image) {
                    return $this->returnErrorResponse('Image not found', 'The specified image does not exist');
                }

                $studio = $this->studioService->setStudioImage($id, $image);
                return $this->returnResponse('studio', new StudioResource($studio));
            }

            // Legacy flow: process file through server
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $date = date('Ymdi');
                $extension = $file->getClientOriginalExtension() ?: 'jpeg';
                $filename = "studio_" . $id . "_" . $date . "." . $extension;

                $fileData = base64_encode(file_get_contents($file->getRealPath()));
                $image = $this->imageService->processImage($fileData, $filename);
            } elseif ($request->has('image')) {
                $file = $request->image;
                $date = date('Ymdi');
                $filename = "studio_" . $id . "_" . $date . ".jpeg";

                $image = $this->imageService->processImage($file, $filename);
            } else {
                return $this->returnErrorResponse('No image provided');
            }

            $studio = $this->studioService->setStudioImage($id, $image);

            return $this->returnResponse('studio', new StudioResource($studio));

        } catch (\Exception $e) {
            \Log::error('Unable to upload studio image', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    // Artist Management
    public function getArtists($id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $artists = $this->studioService->getStudioArtists($studio);

        return $this->returnResponse('artists', StudioArtistResource::collection($artists));
    }

    public function addArtist(Request $request, $id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        // Accept either username or email
        $identifier = $request->input('username') ?? $request->input('email') ?? $request->input('identifier');
        if (!$identifier) {
            return $this->returnErrorResponse('Username or email is required', 422);
        }

        $artist = $this->studioService->addArtistByUsernameOrEmail($studio, $identifier, 'studio');
        if (!$artist) {
            return $this->returnErrorResponse('Artist not found with that username or email', 404);
        }

        // Send notification to the artist
        try {
            $invitedBy = $request->user();
            $artist->notify(new \App\Notifications\StudioInvitationNotification($studio, $invitedBy));
        } catch (\Exception $e) {
            \Log::warning('Failed to send studio invitation notification', [
                'artist_id' => $artist->id,
                'studio_id' => $studio->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Load image relation for consistent response
        $artist->load('image');

        return $this->returnResponse('artist', new DashboardArtistResource($artist));
    }

    public function removeArtist($id, $userId): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $removed = $this->studioService->removeArtist($studio, $userId);
        if (!$removed) {
            return $this->returnErrorResponse('Artist was not associated with this studio', 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Verify an artist at this studio.
     */
    public function verifyArtist(Request $request, $id, $userId): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        // Check if artist is associated with this studio
        $artist = $studio->artists()->where('users.id', $userId)->first();
        if (!$artist) {
            return $this->returnErrorResponse('Artist is not associated with this studio', 404);
        }

        // Check if this was an artist-initiated request (so we can notify them)
        $wasArtistRequest = $artist->pivot->initiated_by === 'artist';

        // Update the pivot to mark as verified
        $studio->artists()->updateExistingPivot($userId, [
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        // If the artist requested to join, notify them that they've been approved
        if ($wasArtistRequest) {
            try {
                $artist->notify(new \App\Notifications\AffiliationAcceptedNotification($studio->owner ?? $request->user(), $studio, 'studio'));
            } catch (\Exception $e) {
                \Log::warning('Failed to send affiliation accepted notification', [
                    'artist_id' => $userId,
                    'studio_id' => $studio->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Artist verified successfully',
        ]);
    }

    /**
     * Unverify (reject) an artist at this studio.
     */
    public function unverifyArtist(Request $request, $id, $userId): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        // Check if artist is associated with this studio
        $artist = $studio->artists()->where('users.id', $userId)->first();
        if (!$artist) {
            return $this->returnErrorResponse('Artist is not associated with this studio', 404);
        }

        // Update the pivot to mark as unverified
        $studio->artists()->updateExistingPivot($userId, [
            'is_verified' => false,
            'verified_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Artist verification removed',
        ]);
    }

    // Gallery - tattoos from affiliated artists
    public function getGallery(Request $request, $id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        // Get artist IDs from verified affiliated artists
        $artistIds = $studio->verifiedArtists()->pluck('users.id')->toArray();

        // Also include the studio owner if they are an artist
        if ($studio->owner_id) {
            $owner = User::find($studio->owner_id);
            if ($owner && $owner->type_id === \App\Enums\UserTypes::ARTIST_TYPE_ID) {
                $artistIds[] = $studio->owner_id;
                $artistIds = array_unique($artistIds);
            }
        }

        if (empty($artistIds)) {
            return response()->json([
                'gallery' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $request->get('limit', 20),
                    'total' => 0,
                ],
            ]);
        }

        // Use Elasticsearch for speed
        $limit = $request->get('limit', 20);
        $page = $request->get('page', 1);

        $search = \App\Models\Tattoo::search();

        // Filter by artist IDs
        $search->whereIn('artist_id', $artistIds);

        // Sort by featured first, then newest
        $search->sort('is_featured', 'desc');
        $search->sort('created_at', 'desc');

        // Pagination
        $search->take($limit);

        $results = $search->get();

        $tattoos = $results['response'] ?? collect();
        $total = $results['total'] ?? 0;

        // Mix tattoos from different artists together (shuffle within featured/non-featured groups)
        // This prevents one artist's work from being stacked together
        if ($tattoos->count() > 1) {
            $featured = $tattoos->filter(fn($t) => !empty($t['is_featured']))->shuffle();
            $nonFeatured = $tattoos->filter(fn($t) => empty($t['is_featured']))->shuffle();
            $tattoos = $featured->concat($nonFeatured)->values();
        }

        // Elasticsearch returns data already formatted via TattooIndexResource during indexing
        // So we return it directly without passing through TattooResource again
        return response()->json([
            'gallery' => $tattoos,
            'meta' => [
                'current_page' => $page,
                'last_page' => ceil($total / $limit) ?: 1,
                'per_page' => $limit,
                'total' => $total,
            ],
        ]);
    }

    // Announcements
    public function getAnnouncements($id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        return $this->returnResponse('announcements', $studio->announcements);
    }

    public function createAnnouncement(Request $request, $id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $announcement = $this->studioService->createAnnouncement($studio, $request->all());
        return $this->returnResponse('announcement', $announcement);
    }

    public function updateAnnouncement(Request $request, $id, $announcementId): JsonResponse
    {
        $announcement = StudioAnnouncement::where('studio_id', $id)
            ->where('id', $announcementId)
            ->first();

        if (!$announcement) {
            return $this->returnErrorResponse('Announcement not found', 404);
        }

        $updated = $this->studioService->updateAnnouncement($announcement, $request->all());
        return $this->returnResponse('announcement', $updated);
    }

    public function deleteAnnouncement($id, $announcementId): JsonResponse
    {
        $announcement = StudioAnnouncement::where('studio_id', $id)
            ->where('id', $announcementId)
            ->first();

        if (!$announcement) {
            return $this->returnErrorResponse('Announcement not found', 404);
        }

        $this->studioService->deleteAnnouncement($announcement);
        return response()->json(['success' => true]);
    }

    // Spotlights
    public function getSpotlights($id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $spotlights = $this->studioService->getSpotlightsWithData($studio);
        return $this->returnResponse('spotlights', $spotlights);
    }

    public function addSpotlight(Request $request, $id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $request->validate([
            'type' => 'required|in:artist,tattoo',
            'item_id' => 'required|integer',
        ]);

        $spotlight = $this->studioService->addSpotlight(
            $studio,
            $request->input('type'),
            $request->input('item_id'),
            $request->input('display_order', 0)
        );

        return $this->returnResponse('spotlight', $spotlight);
    }

    public function removeSpotlight($id, $spotlightId): JsonResponse
    {
        $spotlight = StudioSpotlight::where('studio_id', $id)
            ->where('id', $spotlightId)
            ->first();

        if (!$spotlight) {
            return $this->returnErrorResponse('Spotlight not found', 404);
        }

        $this->studioService->removeSpotlight($spotlight);
        return response()->json(['success' => true]);
    }

    // ==================== Admin Methods ====================

    /**
     * List all studios with pagination for admin
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $pagination = $this->paginationService->extractParams($request);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'desc');
        $filter = $request->input('filter', []);

        $query = Studio::with(['owner', 'image']);

        // Apply filters
        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?? [];
        }

        if (!empty($filter['q'])) {
            $search = $filter['q'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // Apply sorting
        $query->orderBy($sort, $order);

        $total = $query->count();
        $studios = $this->paginationService->applyToQuery($query, $pagination['offset'], $pagination['per_page'])->get();

        return response()->json([
            'data' => $studios,
            'total' => $total,
        ]);
    }

    /**
     * Create a new studio (admin only)
     */
    public function adminStore(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $studio = Studio::create([
            'name' => $request->input('name'),
            'slug' => $request->input('slug', strtolower(str_replace(' ', '-', $request->input('name')))),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'location' => $request->input('location', ''),
            'about' => $request->input('about'),
            'owner_id' => $request->input('owner_id'),
        ]);

        return response()->json([
            'data' => $studio,
        ], 201);
    }

    /**
     * Get a single studio for admin
     */
    public function adminShow(int $id): JsonResponse
    {
        $studio = Studio::with(['owner', 'image', 'artists'])->find($id);

        if (!$studio) {
            return response()->json([
                'success' => false,
                'message' => 'Studio not found',
            ], 404);
        }

        return response()->json([
            'data' => $studio,
        ]);
    }

    /**
     * Update any studio (admin only)
     */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (!$studio) {
            return response()->json([
                'success' => false,
                'message' => 'Studio not found',
            ], 404);
        }

        $data = $request->all();

        foreach ($data as $fieldName => $fieldVal) {
            if (in_array($fieldName, $studio->getFillable())) {
                $studio->{$fieldName} = $fieldVal;
            }
        }

        $studio->save();

        return response()->json([
            'data' => $studio,
        ]);
    }

    /**
     * Delete a studio (admin only)
     */
    public function adminDestroy(int $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (!$studio) {
            return response()->json([
                'success' => false,
                'message' => 'Studio not found',
            ], 404);
        }

        $studio->delete();

        return response()->json([
            'data' => ['id' => $id],
        ]);
    }

    /**
     * Get dashboard statistics for a studio.
     */
    public function getDashboardStats(Request $request, int $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (!$studio) {
            return response()->json(['error' => 'Studio not found'], 404);
        }

        // Validate that the authenticated user is the studio owner
        $user = $request->user();
        if (!$user || $studio->owner_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json(new StudioStatsResource($this->studioService->getStudioStatsData($studio)));
    }

    /**
     * Get all dashboard data for a studio in a single request.
     * Combines: studio details, artists, announcements, stats, and working hours.
     */
    public function dashboard(Request $request, int $id): JsonResponse
    {
        $studio = Studio::with(['image', 'address', 'announcements'])->find($id);

        if (!$studio) {
            return response()->json(['error' => 'Studio not found'], 404);
        }

        // Validate that the authenticated user is the studio owner
        $user = $request->user();
        if (!$user || $studio->owner_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get artists with verification data
        $artists = $this->studioService->getStudioArtists($studio);

        // Get working hours
        $workingHours = StudioAvailability::where('studio_id', $studio->id)->get();

        // Get cached stats
        $stats = $this->studioService->getStudioStatsData($studio);

        return new StudioDashboardResource(
            $studio,
            StudioArtistResource::collection($artists)->toArray($request),
            $studio->announcements,
            $stats,
            $workingHours
        );
    }
}
