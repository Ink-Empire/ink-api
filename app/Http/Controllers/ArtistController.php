<?php

namespace App\Http\Controllers;

use App\Http\Resources\Elastic\ArtistResource;
use App\Http\Resources\WorkingHoursResource;
use App\Jobs\NotifyWishlistUsersOfBooksOpen;
use App\Models\Appointment;
use App\Models\Artist;
use App\Models\ArtistAvailability;
use App\Models\ArtistSettings;
use App\Models\Message;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\ArtistService;
use App\Services\ImageService;
use App\Services\TattooService;
use App\Services\GooglePlacesService;
use App\Services\SearchImpressionService;
use App\Util\ModelLookup;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 *
 */
class ArtistController extends Controller
{
    public function __construct(
        protected ArtistService $artistService,
        protected ImageService  $imageService,
        protected TattooService $tattooService,
        protected GooglePlacesService $googlePlacesService,
        protected SearchImpressionService $impressionService
    )
    {
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $params = $request->all();

        $response = $this->artistService->search($params);

        // Get unclaimed studios if we have location coordinates and not searching "Anywhere"
        $unclaimedStudios = $this->getUnclaimedStudios($params, $request);

        // Sanitize response - $request->user() works via auth.optional middleware
        $sanitizedResponse = $this->sanitizeArtistData($response['response'], $request->user());

        return response()->json([
            'response' => $sanitizedResponse,
            'unclaimed_studios' => $unclaimedStudios,
            'total' => $response['total'] ?? null,
        ]);
    }

    /**
     * Get unclaimed studios from Google Places based on search params
     */
    protected function getUnclaimedStudios(array $params, Request $request): array
    {
        // Don't hit Google Places API when viewing demo data
        if (!empty($params['is_demo'])) {
            return [];
        }

        $locationCoords = $params['locationCoords'] ?? null;
        $useAnyLocation = $params['useAnyLocation'] ?? false;

        // Don't search Google Places if no location or searching "Anywhere"
        if (!$locationCoords || $useAnyLocation) {
            return [];
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

        return $unclaimedStudios;
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

        $params = request()->all();

        $artist = $this->artistService->getById($id);
        $tattoos = $this->tattooService->getByArtistId($id, $params);

        // Replace embedded tattoos with fresh data from tattoos index
        if (is_array($artist) && isset($artist['tattoos'])) {
            $tattooData = $tattoos['response'];

            // Convert Collection to array if needed
            if ($tattooData instanceof \Illuminate\Support\Collection) {
                $tattooData = $tattooData->values()->toArray();
            }

            $artist['tattoos'] = $tattooData;
        }

        // Only sanitize PII if no auth token (unauthenticated request)
        if (!request()->user()) {
            $artist = $this->sanitizeSingleArtist($artist);
        }

        return $this->returnResponse('artist', $artist);
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

        $availability = ArtistAvailability::where('artist_id', $artist->id)->get();

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
                    'is_day_off' => $availability['is_day_off']
                ]
            );
        }

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
     * Sanitize and filter artist data.
     * - Filters out blocked users (for authenticated users)
     * - Removes PII (for unauthenticated users)
     */
    private function sanitizeArtistData($artists, $user): mixed
    {
        // Get blocked user IDs if authenticated
        $blockedIds = $user ? $user->getAllBlockedIds() : [];

        // Filter function to remove blocked artists
        $filterBlocked = function ($artist) use ($blockedIds) {
            if (empty($blockedIds)) return true;
            $artistId = is_array($artist) ? ($artist['id'] ?? null) : ($artist->id ?? null);
            return !in_array($artistId, $blockedIds);
        };

        if ($artists instanceof \Illuminate\Support\Collection) {
            $filtered = $artists->filter($filterBlocked);
            return $user ? $filtered->values() : $filtered->map(fn($artist) => $this->sanitizeSingleArtist($artist))->values();
        }

        if (is_array($artists)) {
            $filtered = array_filter($artists, $filterBlocked);
            return $user ? array_values($filtered) : array_values(array_map(fn($artist) => $this->sanitizeSingleArtist($artist), $filtered));
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
     * @return void
     */
    public function delete()
    {

    }

    /**
     * Get dashboard statistics for an artist
     */
    public function getDashboardStats(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Validate that the authenticated user is the artist
        $user = $request->user();
        if (!$user || $user->id !== $artist->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $now = Carbon::now();
        $sevenDaysAgo = $now->copy()->subDays(7);
        $fourteenDaysAgo = $now->copy()->subDays(14);

        // Profile views - this week vs last week
        $viewsThisWeek = ProfileView::where('viewable_type', User::class)
            ->where('viewable_id', $artist->id)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count();

        $viewsLastWeek = ProfileView::where('viewable_type', User::class)
            ->where('viewable_id', $artist->id)
            ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
            ->count();

        $viewsTrend = $viewsLastWeek > 0
            ? round((($viewsThisWeek - $viewsLastWeek) / $viewsLastWeek) * 100)
            : ($viewsThisWeek > 0 ? 100 : 0);

        // Saves count - users who saved this artist
        $savesCount = DB::table('users_artists')
            ->where('artist_id', $artist->id)
            ->count();

        // Saves this week vs last week (if users_artists has timestamps)
        $savesThisWeek = DB::table('users_artists')
            ->where('artist_id', $artist->id)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count();

        $savesLastWeek = DB::table('users_artists')
            ->where('artist_id', $artist->id)
            ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
            ->count();

        $savesTrend = $savesThisWeek - $savesLastWeek;

        // Upcoming appointments
        $upcomingAppointments = Appointment::where('artist_id', $artist->id)
            ->where('status', 'booked')
            ->where('date', '>=', $now->toDateString())
            ->count();

        // Appointments trend (this week scheduled vs last week)
        $appointmentsThisWeek = Appointment::where('artist_id', $artist->id)
            ->where('status', 'booked')
            ->whereBetween('date', [$now->toDateString(), $now->copy()->addDays(7)->toDateString()])
            ->count();

        $appointmentsLastWeek = Appointment::where('artist_id', $artist->id)
            ->where('status', 'booked')
            ->whereBetween('date', [$sevenDaysAgo->toDateString(), $now->toDateString()])
            ->count();

        $appointmentsTrend = $appointmentsThisWeek - $appointmentsLastWeek;

        // Unread messages count
        $unreadMessages = Message::where('recipient_id', $artist->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => [
                'profile_views' => $viewsThisWeek,
                'profile_views_total' => ProfileView::where('viewable_type', User::class)
                    ->where('viewable_id', $artist->id)
                    ->count(),
                'views_trend' => ($viewsTrend >= 0 ? '+' : '') . $viewsTrend . '%',
                'saves_count' => $savesCount,
                'saves_this_week' => $savesThisWeek,
                'saves_trend' => ($savesTrend >= 0 ? '+' : '') . $savesTrend,
                'upcoming_appointments' => $upcomingAppointments,
                'appointments_trend' => ($appointmentsTrend >= 0 ? '+' : '') . $appointmentsTrend,
                'unread_messages' => $unreadMessages,
            ]
        ]);
    }

    /**
     * Get upcoming schedule for an artist
     */
    public function getUpcomingSchedule(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $appointments = Appointment::where('artist_id', $artist->id)
            ->where('status', 'booked')
            ->where('date', '>=', Carbon::now()->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit(10)
            ->with('client')
            ->get();

        $schedule = $appointments->map(function ($apt) {
            $date = Carbon::parse($apt->date);
            $startTime = Carbon::parse($apt->start_time)->format('g:i A');
            $endTime = Carbon::parse($apt->end_time)->format('g:i A');

            $clientName = $apt->client?->name ?? 'Unknown Client';
            $nameParts = explode(' ', $clientName);
            $initials = count($nameParts) >= 2
                ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1))
                : strtoupper(substr($clientName, 0, 2));

            return [
                'id' => $apt->id,
                'day' => $date->day,
                'month' => $date->format('M'),
                'time' => "{$startTime} – {$endTime}",
                'title' => $apt->title ?? 'Appointment',
                'clientName' => $clientName,
                'clientInitials' => $initials,
                'type' => $apt->type ?? 'appointment',
            ];
        });

        return response()->json(['data' => $schedule]);
    }
}
