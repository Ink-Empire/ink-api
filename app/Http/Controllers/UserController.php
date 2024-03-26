<?php

namespace App\Http\Controllers;

use App\Enums\UserRelationships;
use App\Exceptions\UserNotFoundException;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AddressService;
use App\Services\ImageService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 *
 */
class UserController
{

    public function __construct(
        protected UserService    $userService,
        protected ImageService   $imageService,
        protected AddressService $addressService
    )
    {
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get($id)
    {
        $user = $this->userService->getById($id);
        return $this->returnUserResponse($user);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        try {
            $data = $request->get('payload');

            if ($data['payload']['address']) {
                $address = $this->addressService->create(
                    $this->addressService->mapFields($data['payload']['address'])
                );
            }

            $user = new User([
                'name' => $data['payload']['name'],
                'email' => $data['payload']['email'],
                'password' => bcrypt($data['payload']['password']),
                'phone' => $data['payload']['phone'] ?? null,
                'location' => $data['payload']['address'] ?? null,
                'type_id' => $data['payload']['type'] == 'client' ? 1 : 2,
                'address_id' => $address->id ?? null
            ]);
            $user->save();
            return $this->returnUserResponse($user);
        } catch (\Exception $e) {
            \Log::error("Unable to create user", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            return $this->returnErrorResponse($e->getMessage());
        }
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

            return $this->returnUserResponse($user);

        } catch (UserNotFoundException $e) {
            \Log::error("Unable to find user with id of $user_id");

            return $this->returnErrorResponse($e->getMessage(), "User $user_id not found");
        }
    }

    /**
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();
            $user = $this->userService->getById($id);
            foreach ($data as $fieldName => $fieldVal) {
                if (in_array($fieldName, $user->getFillable())) {
                    $user->{$fieldName} = $fieldVal;
                }

                if (in_array($fieldName, array_keys(UserRelationships::RELATIONSHIPS))) {
                    foreach ($fieldVal as $val) {

                        $instance = UserRelationships::RELATIONSHIPS[$fieldName];
                        $toSave = new $instance($val);
                        $user->{$fieldName}()->syncWithoutDetaching($toSave);
                    }
                }
            }
            $user->save();
            return $this->returnUserResponse('user', new UserResource($user));
        } catch (\Exception $e) {
            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * @return JsonResponse
     */
    public function updateFavorite(Request $request, $id)
    {
        $data = $request->get('body');
        $user = $this->userService->getById($id);

        foreach (UserRelationships::RELATIONSHIPS as $relationship_name => $class) {
            if (isset($data[$relationship_name])) {

                foreach ($data[$relationship_name] as $relationship) {
                    $instance = new $class(['id' => $relationship['id']]); //returns a new User or Artists class with attributes

                    if ($data['isFavorite']) {
                        $user->{$relationship_name}()->syncWithoutDetaching($instance);
                    } else {
                        $user->{$relationship_name}()->detach($instance);
                    }
                }
            }
        }

        $user->save();

        return $this->returnUserResponse($user);
    }

    /**
     * @return void
     */
    public function delete()
    {

    }

    private function returnUserResponse($user): JsonResponse
    {
        return response()->json(['user' => new UserResource($user)]);
    }

    private function returnErrorResponse($error, $errorMessage = 'error'): JsonResponse
    {
        return response()->json([$errorMessage => $error]);
    }
}
