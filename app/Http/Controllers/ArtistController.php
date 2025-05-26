<?php

namespace App\Http\Controllers;

use App\Http\Resources\Elastic\ArtistResource;
use App\Http\Resources\WorkingHoursResource;
use App\Models\Artist;
use App\Models\ArtistAvailability;
use App\Models\ArtistSettings;
use App\Models\User;
use App\Services\ArtistService;
use App\Services\ImageService;
use App\Services\SearchService;
use App\Util\ModelLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *
 */
class ArtistController extends Controller
{
    public function __construct(
        protected ArtistService $artistService,
        protected ImageService  $imageService,
        protected SearchService $searchService
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

        return $this->returnElasticResponse($response);
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
    public function getById($id): JsonResponse
    {
        if (request()->query('db')) {
            $artist = ModelLookup::findArtist($id);
            return $this->returnResponse('artist', new ArtistResource($artist));
        }
        $artist = $this->searchService->getById($id, 'artist');

        return $this->returnResponse('artist', $artist);
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

        $availability = ArtistAvailability::where('artist_id', $artist->id)->get();

        return WorkingHoursResource::collection($availability);
    }

    public function setAvailability(Request $request)
    {
        $artist = $request->user();

        $availabilityArray = $request->get('availability');

        foreach ($availabilityArray as $availability) {
            // create an object from ArtistAvailability
            $availabilityObj = new ArtistAvailability([
                'artist_id' => $artist->id,
                'day_of_week' => $availability['day_of_week'],
                'start_time' => $availability['start_time'],
                'end_time' => $availability['end_time'],
                'is_day_off' => $availability['is_day_off']
            ]);

            // save the object to the database
            $availabilityObj->save();
        }
    }

    public function portfolio(Request $request, $id): JsonResponse
    {
        $response = $this->artistService->getById($id);

        if ($response) {
            return response()->json($response->first()['tattoos']);
        }

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

        $settings = ArtistSettings::where('artist_id', $artist->id)->first();

        if (!$settings) {
            // Return default settings if none exist
            $defaultSettings = [
                'books_open' => false,
                'accepts_walk_ins' => false,
                'accepts_deposits' => false,
                'accepts_consultations' => false,
                'accepts_appointments' => false,
            ];

            return response()->json(['data' => $defaultSettings]);
        }

        return response()->json(['data' => $settings->only([
            'books_open',
            'accepts_walk_ins',
            'accepts_deposits',
            'accepts_consultations',
            'accepts_appointments'
        ])]);
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
            'accepts_appointments'
        ];

        $settingsData = $request->only($validSettings);

        // Convert values to boolean
        foreach ($settingsData as $key => $value) {
            $settingsData[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $settings = ArtistSettings::updateOrCreate(
            ['artist_id' => $artist->id],
            $settingsData
        );

        //we need to re-index the artist
        $artist->searchable();

        return response()->json(['data' => $settings->only($validSettings)]);
    }

    /**
     * @return void
     */
    public function delete()
    {

    }

}
