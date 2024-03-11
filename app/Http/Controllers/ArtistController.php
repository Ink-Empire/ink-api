<?php

namespace App\Http\Controllers;

use App\Exceptions\UserNotFoundException;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ArtistService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 *
 */
class ArtistController
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

        return response()->json(['artists' => ArtistResource::collection($artists)]);
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id, $user_id = null)
    {
        $artist = $this->artistService->getById($id);
        $artist->user_id = $user_id;

        return response()->json(['artist' => new ArtistResource($artist)]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        $data = $request->get('payload');

        $user = new User([
            'name' => $data['payload']['name'],
            'email' => $data['payload']['email'],
            'password' => bcrypt($data['payload']['password']),
            'phone' => $data['payload']['phone'] ?? null,
            'location' => $data['payload']['address'] ?? null,
            'type_id' => $data['payload']['type'] == 'client' ? 1 : 2,
        ]);

        $user->save();

        return response()->json(['user' => $user]);
    }

    /**
     * @param Request $request
     */
    public function upload(Request $request): JsonResponse|Response
    {
        try {
            $data = $request->all();

            $file = $request->get('my_file');

            $user_id = $request->get('user_id');
            $date = Date('Ymdi');
            $filename = "profile_" . $data['user_id'] . $date . ".jpeg";

            $image = $this->imageService->processImage($file, $filename);

            $user = $this->userService->setProfileImage($user_id, $image);

            return response()->json(['user' => new UserResource($user)]);
        } catch (UserNotFoundException $e) {
            \Log::error("Unable to find user with id of $user_id");

            return response("User $user_id not found", 500);
        }
    }

    /**
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $data = $request->get('payload');
        $user = $this->userService->getById($id);

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

    private function getModelInstance($name)
    {
        if ($name == 'artists') { //artist is derived from users table
            $name = 'user';
        } else {
            $name = substr($name, 0, -1);
        }
        return app("App\Models\\" . $name);
    }
}
