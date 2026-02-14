<?php

namespace App\Http\Controllers;

use App\Http\Resources\Dashboard\ArtistDashboardResource;
use App\Http\Resources\Elastic\ArtistResource;
use App\Http\Resources\WorkingHoursResource;
use App\Jobs\NotifyWishlistUsersOfBooksOpen;
use App\Models\Appointment;
use App\Models\Artist;
use App\Models\ArtistAvailability;
use App\Models\ArtistSettings;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\ArtistService;
use App\Services\ImageService;
use App\Services\TattooService;
use App\Services\PaginationService;
use App\Util\ModelLookup;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 *
 */
class ArtistController extends Controller
{
    public function __construct(
        protected ArtistService $artistService,
        protected ImageService $imageService,
        protected TattooService $tattooService,
        protected PaginationService $paginationService
    ) {
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $params = $request->all();
        $pagination = $this->paginationService->extractParams($params);

        $response = $this->artistService->search($params);

        // Remove PII for unauthenticated users
        $sanitizedResponse = $request->user()
            ? $response['response']
            : $this->sanitizePii($response['response']);

        $total = $response['total'] ?? 0;
        $paginationMeta = $this->paginationService->buildMeta($total, $pagination['page'], $pagination['per_page']);

        return response()->json([
            'response' => $sanitizedResponse,
            ...$paginationMeta,
        ]);
    }



    public function get(Request $request)
    {
        $params = $request->all();

        $response = $this->artistService->get();

        return $this->returnElasticResponse($response);
    }

    //TODO wire these to get results from ES

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById(Request $request, $id): JsonResponse
    {
        // Resolve slug/id to get the artist model for block checking
        $artistModel = ModelLookup::findArtist($id);

        if (!$artistModel) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Check if the authenticated user has blocked or is blocked by this artist
        $user = $request->user();
        if ($user && $user->isBlocked($artistModel->id)) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        if ($request->query('db')) {
            return $this->returnResponse('artist', new ArtistResource($artistModel));
        }

        $params = $request->all();

        $artist = $this->artistService->getById($id);
        $tattoos = $this->tattooService->getByArtistId($id, $params);

        // Add tattoos from the tattoos index (not embedded in artist index)
        if (is_array($artist)) {
            $tattooData = $tattoos['response'] ?? [];

            // Convert Collection to array if needed
            if ($tattooData instanceof \Illuminate\Support\Collection) {
                $tattooData = $tattooData->values()->toArray();
            }

            $artist['tattoos'] = $tattooData;
        }

        // Only sanitize PII if no auth token (unauthenticated request)
        if (!$request->user()) {
            $artist = $this->sanitizeSingleArtist($artist);
        }

        return $this->returnResponse('artist', $artist);
    }

    /**
     * Get an artist's tattoo portfolio with pagination.
     * Accepts artist ID or slug.
     */
    public function getPortfolio(Request $request, $id): JsonResponse
    {
        $artistModel = ModelLookup::findArtist($id);

        if (!$artistModel) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $params = $request->all();
        $pagination = $this->paginationService->extractParams($params);

        $tattoos = $this->tattooService->getByArtistId($id, $params);

        $tattooData = $tattoos['response'] ?? [];
        if ($tattooData instanceof \Illuminate\Support\Collection) {
            $tattooData = $tattooData->values()->toArray();
        }

        $total = $tattoos['total'] ?? count($tattooData);
        $paginationMeta = $this->paginationService->buildMeta($total, $pagination['page'], $pagination['per_page']);

        return response()->json([
            'response' => $tattooData,
            ...$paginationMeta,
        ]);
    }

    /**
     * Lookup an artist by username or email.
     * Used to validate an artist exists before adding them to a studio.
     */
    public function lookupByIdentifier(Request $request): JsonResponse
    {
        $identifier = $request->input('username') ?? $request->input('email') ?? $request->input('identifier');
        if (!$identifier) {
            return $this->returnErrorResponse('Username or email is required', 422);
        }

        $identifierLower = strtolower($identifier);

        // Try username first via Elasticsearch
        $results = Artist::search()
            ->where('username', $identifierLower)
            ->take(1)
            ->get();

        $result = collect($results['response'] ?? $results)->first();

        // If not found by username, try email
        if (!$result) {
            $results = Artist::search()
                ->where('email', $identifierLower)
                ->take(1)
                ->get();

            $result = collect($results['response'] ?? $results)->first();
        }

        if (!$result) {
            return $this->returnErrorResponse('No artist found with that username or email', 404);
        }

        // Load the model with image relation for proper resource response
        $artist = Artist::with('image')->find($result['id']);

        if (!$artist) {
            return $this->returnErrorResponse('No artist found with that username or email', 404);
        }

        return $this->returnResponse('artist', new ArtistDashboardResource($artist));
    }

    /**
     * Record a profile view for an artist
     */
    public function recordView(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Don't record views of your own profile
        $viewer = $request->user();
        if ($viewer && $viewer->id === $artist->id) {
            return response()->json(['success' => true, 'recorded' => false]);
        }

        ProfileView::create([
            'viewer_id' => $viewer?->id,
            'viewable_type' => User::class,
            'viewable_id' => $artist->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->header('referer'),
        ]);

        return response()->json(['success' => true, 'recorded' => true]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        $data = $request->get('payload');

        $artist = new User([
            'name' => $data['payload']['name'],
            'email' => $data['payload']['email'],
            'password' => bcrypt($data['payload']['password']),
            'phone' => $data['payload']['phone'] ?? null,
            'location' => $data['payload']['address'] ?? null,
            'type_id' => $data['payload']['type'] == 'client' ? 1 : 2,
        ]);

        return $this->returnResponse('artist', new ArtistResource($artist));
    }

    /**
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $data = $request->get('payload');
        $user = $this->artistService->getById($id);

        foreach ($data['payload'] as $fieldName => $fieldVal) {
            if (in_array($fieldName, $user->getFillable())) {
                $user->{$fieldName} = $fieldVal;
            }

            if (in_array($fieldName, self::USER_RELATIONSHIPS)) {

                foreach ($fieldVal as $val) {
                    $instance = $this->getModelInstance($fieldName);
                    $toSave = new $instance($val);
                    $user->{$fieldName}()->syncWithoutDetaching($toSave);
                }
            }
        }

        $user->save();

        return response()->json(['user' => $user]);
    }

    public function getAvailability(Request $request, $id)
    {
        //id may be a slug, support this
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Check if blocked
        $user = $request->user();
        if ($user && $user->isBlocked($artist->id)) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $availability = Cache::remember(
            "artist:{$artist->id}:working-hours",
            3600, // 1 hour
            fn () => ArtistAvailability::where('artist_id', $artist->id)->get()
        );

        return WorkingHoursResource::collection($availability);
    }

    public function setAvailability(Request $request)
    {
        $artist = $request->user();

        $availabilityArray = $request->get('availability');

        foreach ($availabilityArray as $availability) {
            ArtistAvailability::updateOrCreate(
                [
                    'artist_id' => $artist->id,
                    'day_of_week' => $availability['day_of_week']
                ],
                [
                    'start_time' => $availability['start_time'],
                    'end_time' => $availability['end_time'],
                    'consultation_start_time' => $availability['consultation_start_time'] ?? null,
                    'consultation_end_time' => $availability['consultation_end_time'] ?? null,
                    'is_day_off' => $availability['is_day_off'],
                ]
            );
        }

        Cache::forget("artist:{$artist->id}:working-hours");

        return response()->json(['success' => true]);
    }

    /**
     * Get artist settings
     */
    public function getSettings(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Check if blocked
        $user = $request->user();
        if ($user && $user->isBlocked($artist->id)) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $settings = ArtistSettings::where('artist_id', $artist->id)->with('watermarkImage')->first();

        if (!$settings) {
            // Return default settings if none exist
            $defaultSettings = [
                'books_open' => false,
                'accepts_walk_ins' => false,
                'accepts_deposits' => false,
                'accepts_consultations' => false,
                'accepts_appointments' => false,
                'hourly_rate' => 0,
                'deposit_amount' => 0,
                'consultation_fee' => 0,
                'consultation_duration' => 30,
                'minimum_session' => null,
                'watermark_enabled' => false,
                'watermark_opacity' => 50,
                'watermark_position' => 'bottom-right',
                'watermark_image' => null,
            ];

            return response()->json(['data' => $defaultSettings]);
        }

        $data = $settings->only([
            'books_open',
            'accepts_walk_ins',
            'accepts_deposits',
            'accepts_consultations',
            'accepts_appointments',
            'hourly_rate',
            'deposit_amount',
            'consultation_fee',
            'consultation_duration',
            'minimum_session',
            'watermark_enabled',
            'watermark_opacity',
            'watermark_position',
            'watermark_image_id',
        ]);

        // Include watermark image details
        $data['watermark_image'] = $settings->watermarkImage ? [
            'id' => $settings->watermarkImage->id,
            'uri' => $settings->watermarkImage->uri,
        ] : null;

        return response()->json(['data' => $data]);
    }

    /**
     * Update artist settings
     */
    public function updateSettings(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Validate that the authenticated user is the artist or has permission
        $user = $request->user();
        if (!$user || $user->id !== $artist->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validSettings = [
            'books_open',
            'accepts_walk_ins',
            'accepts_deposits',
            'accepts_consultations',
            'accepts_appointments',
            'hourly_rate',
            'deposit_amount',
            'consultation_fee',
            'consultation_duration',
            'minimum_session',
            'watermark_image_id',
            'watermark_opacity',
            'watermark_position',
            'watermark_enabled',
        ];

        $settingsData = $request->only($validSettings);

        // Validate watermark_image_id if provided
        if (isset($settingsData['watermark_image_id']) && $settingsData['watermark_image_id']) {
            $imageExists = \App\Models\Image::where('id', $settingsData['watermark_image_id'])->exists();
            if (!$imageExists) {
                return response()->json(['error' => 'Invalid watermark image'], 400);
            }
        }

        // Check if books_open is being changed from false to true
        $existingSettings = ArtistSettings::where('artist_id', $artist->id)->first();
        $wasBooksClosed = !$existingSettings || !$existingSettings->books_open;
        $isOpeningBooks = !empty($settingsData['books_open']) && $wasBooksClosed;

        // Require at least one non-day-off availability row before opening books
        if (!empty($settingsData['books_open'])) {
            $hasAvailability = ArtistAvailability::where('artist_id', $artist->id)
                ->where('is_day_off', false)
                ->exists();

            if (!$hasAvailability) {
                return response()->json([
                    'error' => 'Please set your available hours before opening your books.',
                    'requires_availability' => true,
                ], 422);
            }
        }

        // If books_open is being set to true, automatically enable accepts_appointments
        if (!empty($settingsData['books_open'])) {
            $settingsData['accepts_appointments'] = true;
        }

        $settings = ArtistSettings::updateOrCreate(
            ['artist_id' => $artist->id],
            $settingsData
        );

        // Notify wishlist users if books just opened
        if ($isOpeningBooks) {
            NotifyWishlistUsersOfBooksOpen::dispatch($artist->id);
        }

        // Refresh the settings relationship so searchable() gets fresh data
        $artist->load('settings');
        $artist->searchable();

        // Load watermark image for response
        $settings->load('watermarkImage');
        $responseData = $settings->only($validSettings);
        $responseData['watermark_image'] = $settings->watermarkImage ? [
            'id' => $settings->watermarkImage->id,
            'uri' => $settings->watermarkImage->uri,
        ] : null;

        return response()->json(['data' => $responseData]);
    }

    /**
     * Remove PII from artist data for unauthenticated users.
     */
    private function sanitizePii($artists): mixed
    {
        if ($artists instanceof \Illuminate\Support\Collection) {
            return $artists->map(fn($artist) => $this->sanitizeSingleArtist($artist))->values();
        }

        if (is_array($artists)) {
            return array_values(array_map(fn($artist) => $this->sanitizeSingleArtist($artist), $artists));
        }

        return $artists;
    }

    /**
     * Sanitize a single artist record to remove PII fields for unauthenticated access.
     * Only removes: email, phone, password, location coordinates
     * Booking info (rates, etc.) remains visible to everyone.
     */
    private function sanitizeSingleArtist($artist): mixed
    {
        if (!$artist) {
            return $artist;
        }

        // Only hide PII fields - booking info is public
        $sensitiveFields = ['email', 'phone', 'password', 'location_lat_long'];

        if (is_array($artist)) {
            foreach ($sensitiveFields as $field) {
                unset($artist[$field]);
            }
        } elseif (is_object($artist)) {
            foreach ($sensitiveFields as $field) {
                unset($artist->{$field});
            }
        }

        return $artist;
    }

    /**
     * Get available time slots for booking with an artist on a specific date.
     */
    public function getAvailableSlots(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $user = $request->user();
        if ($user && $user->isBlocked($artist->id)) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $date = $request->query('date');
        $type = $request->query('type', 'appointment');

        if (!$date) {
            return response()->json(['error' => 'Date is required'], 400);
        }

        $dateObj = Carbon::parse($date);
        $dayOfWeek = $dateObj->dayOfWeek;

        $availability = ArtistAvailability::where('artist_id', $artist->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (!$availability || $availability->is_day_off) {
            return response()->json([
                'date' => $date,
                'working_hours' => null,
                'consultation_window' => null,
                'consultation_duration' => 30,
                'slots' => [],
            ]);
        }

        $settings = ArtistSettings::where('artist_id', $artist->id)->first();
        $consultationDuration = $settings->consultation_duration ?? 30;

        $startTime = Carbon::parse($availability->start_time);
        $endTime = Carbon::parse($availability->end_time);
        $consultStart = $availability->consultation_start_time ? Carbon::parse($availability->consultation_start_time) : null;
        $consultEnd = $availability->consultation_end_time ? Carbon::parse($availability->consultation_end_time) : null;
        $hasConsultWindow = $consultStart && $consultEnd;

        // Get existing appointments for the date
        $existingAppointments = Appointment::where('artist_id', $artist->id)
            ->whereDate('date', $date)
            ->whereIn('status', ['pending', 'booked'])
            ->get();

        // Generate candidate slots
        $slots = [];
        if ($type === 'consultation') {
            $slotStart = $hasConsultWindow ? $consultStart->copy() : $startTime->copy();
            $slotEnd = $hasConsultWindow ? $consultEnd->copy() : $endTime->copy();
            $step = $consultationDuration;

            while ($slotStart->copy()->addMinutes($step)->lte($slotEnd)) {
                $slots[] = [
                    'time' => $slotStart->format('H:i'),
                    'end' => $slotStart->copy()->addMinutes($step)->format('H:i'),
                ];
                $slotStart->addMinutes($step);
            }
        } else {
            // Appointment slots
            $step = 30;
            $cursor = $startTime->copy();

            while ($cursor->lt($endTime)) {
                // If consultation window exists, skip slots within it
                if ($hasConsultWindow && $cursor->gte($consultStart) && $cursor->lt($consultEnd)) {
                    $cursor->addMinutes($step);
                    continue;
                }
                $slots[] = [
                    'time' => $cursor->format('H:i'),
                    'end' => $cursor->copy()->addMinutes($step)->format('H:i'),
                ];
                $cursor->addMinutes($step);
            }
        }

        // Filter out slots that overlap existing appointments
        $availableSlots = [];
        foreach ($slots as $slot) {
            $slotTime = Carbon::parse($slot['time']);
            $slotEndTime = Carbon::parse($slot['end']);
            $overlaps = false;

            foreach ($existingAppointments as $apt) {
                $aptStart = Carbon::parse($apt->start_time);
                $aptEnd = $apt->end_time ? Carbon::parse($apt->end_time) : $aptStart->copy()->addHour();

                // Overlap if slot's range intersects appointment's range
                if ($slotTime->lt($aptEnd) && $slotEndTime->gt($aptStart)) {
                    $overlaps = true;
                    break;
                }
            }

            if (!$overlaps) {
                $availableSlots[] = $slot['time'];
            }
        }

        return response()->json([
            'date' => $date,
            'working_hours' => [
                'start' => $startTime->format('H:i'),
                'end' => $endTime->format('H:i'),
            ],
            'consultation_window' => $hasConsultWindow ? [
                'start' => $consultStart->format('H:i'),
                'end' => $consultEnd->format('H:i'),
            ] : null,
            'consultation_duration' => $consultationDuration,
            'slots' => $availableSlots,
        ]);
    }

    /**
     * @return void
     */
    public function delete()
    {

    }

    /**
     * Get pending studio invitations for the authenticated artist.
     */
    public function getStudioInvitations(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $invitations = $user->pendingStudioInvitations()
            ->with(['image', 'owner'])
            ->get()
            ->map(function ($studio) {
                return [
                    'id' => $studio->id,
                    'name' => $studio->name,
                    'slug' => $studio->slug,
                    'location' => $studio->location,
                    'image' => $studio->image ? [
                        'id' => $studio->image->id,
                        'uri' => $studio->image->uri,
                    ] : null,
                    'owner' => $studio->owner ? [
                        'id' => $studio->owner->id,
                        'name' => $studio->owner->name,
                    ] : null,
                    'invited_at' => $studio->pivot->created_at,
                ];
            });

        return response()->json(['invitations' => $invitations]);
    }

    /**
     * Accept a studio invitation.
     */
    public function acceptStudioInvitation(Request $request, int $studioId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if this invitation exists
        $invitation = $user->pendingStudioInvitations()
            ->where('studios.id', $studioId)
            ->first();

        if (!$invitation) {
            return response()->json(['error' => 'Invitation not found'], 404);
        }

        // Accept: update the pivot to mark as verified
        $user->affiliatedStudios()->updateExistingPivot($studioId, [
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        // Optionally notify the studio owner
        $studio = \App\Models\Studio::find($studioId);
        if ($studio && $studio->owner) {
            try {
                $studio->owner->notify(new \App\Notifications\AffiliationAcceptedNotification($user, $studio, 'artist'));
            } catch (\Exception $e) {
                \Log::warning('Failed to send affiliation accepted notification', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Studio invitation accepted',
        ]);
    }

    /**
     * Decline a studio invitation.
     */
    public function declineStudioInvitation(Request $request, int $studioId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if this invitation exists
        $invitation = $user->pendingStudioInvitations()
            ->where('studios.id', $studioId)
            ->first();

        if (!$invitation) {
            return response()->json(['error' => 'Invitation not found'], 404);
        }

        // Decline: remove the pivot record
        $user->affiliatedStudios()->detach($studioId);

        return response()->json([
            'success' => true,
            'message' => 'Studio invitation declined',
        ]);
    }

    /**
     * Leave a studio affiliation.
     */
    public function leaveStudio(Request $request, int $studioId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            // Check if user is affiliated with this studio
            $affiliation = $user->affiliatedStudios()
                ->where('studios.id', $studioId)
                ->first();

            if (!$affiliation) {
                return response()->json(['error' => 'You are not affiliated with this studio'], 404);
            }

            // Remove the studio affiliation
            $user->affiliatedStudios()->detach($studioId);

            return response()->json([
                'success' => true,
                'message' => 'Studio affiliation removed',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to leave studio', [
                'user_id' => $user->id,
                'studio_id' => $studioId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'You are not affiliated with this studio'], 404);
        }
    }

    /**
     * Set a studio as the primary studio for the artist.
     */
    public function setPrimaryStudio(Request $request, int $studioId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $success = $user->setPrimaryStudio($studioId);

            if (!$success) {
                return response()->json(['error' => 'You are not verified at this studio'], 404);
            }

            // Re-index the artist in Elasticsearch to update the primary studio
            if ($user instanceof \App\Models\Artist || $user->type_id === \App\Enums\UserTypes::ARTIST_TYPE_ID) {
                try {
                    $artist = \App\Models\Artist::find($user->id);
                    if ($artist) {
                        $artist->searchable();
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to re-index artist after setting primary studio', [
                        'artist_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Primary studio updated',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to set primary studio', [
                'user_id' => $user->id,
                'studio_id' => $studioId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'You are not verified at this studio'], 404);
        }
    }
}
