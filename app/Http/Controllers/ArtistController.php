<?php

namespace App\Http\Controllers;

use App\Http\Resources\ArtistResource;
use App\Models\User;
use App\Services\ArtistService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *
 */
class ArtistController extends Controller
{

    public function __construct(
        protected ArtistService  $artistService,
        protected ImageService $imageService
    )
    {
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get($user_id = null)
    {
        session(['user_id' => $user_id]);

        //eventually perhaps replaced with an ES call
        $artists = $this->artistService->get();

        return $this->returnResponse('artists', ArtistResource::collection($artists));
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id, $user_id = null)
    {
        $artist = $this->artistService->getById($id);
        $artist->user_id = $user_id;

        return $this->returnResponse('artist', new ArtistResource($artist));
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

    /**
     * @return void
     */
    public function delete()
    {

    }
}
