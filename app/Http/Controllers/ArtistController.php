<?php

namespace App\Http\Controllers;

use App\Http\Resources\Elastic\ArtistResource;
use App\Http\Resources\WorkingHoursResource;
use App\Models\Artist;
use App\Models\ArtistAvailability;
use App\Models\User;
use App\Services\ArtistService;
use App\Services\ImageService;
use App\Services\SearchService;
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
            $artist = Artist::find($id);
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
        if (is_numeric($id)) {
            $artist = Artist::find($id);
        } else {
            $artist = Artist::where('slug', $id)->first();
        }

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
     * @return void
     */
    public function delete()
    {

    }
}
