<?php

namespace App\Http\Controllers;

use App\Enums\CacheNames;
use App\Exceptions\UserNotFoundException;
use App\Models\User;
use App\Services\ImageService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 *
 */
class UserController
{

    public function __construct(
        protected UserService  $userService,
        protected ImageService $imageService
    )
    {
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get($id)
    {
        $user = Cache::get(CacheNames::USER_CACHE . $id, function () use ($id) {

            $user = $this->userService->getById($id);

            Cache::put(CacheNames::USER_CACHE . $id, $user, now());

            return $user;
        });
        return response()->json(['user' => $user]);
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
    public function upload(Request $request) : JsonResponse|Response
    {
        try {
            $data = $request->all();
            $file = $request->file('my_file');
            $user_id = $request->get('user_id');
            $date = Date('Ymdi');
            $filename = "profile_" . $data['user_id'] . $date;

            $image = $this->imageService->processImage($file, $filename);

            $user = $this->userService->setProfileImage($user_id, $image);

            return response()->json(['image' => $image]);
        } catch (UserNotFoundException $e) {
            \Log::error("Unable to find user with id of $user_id");

            return response("User $user_id not found", 500);
        }
    }

    /**
     * @return void
     */
    public function update()
    {

    }

    /**
     * @return void
     */
    public function delete()
    {

    }

}
