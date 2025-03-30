<?php

namespace App\Http\Controllers;

use App\Enums\UserRelationships;
use App\Enums\UserTypes;
use App\Exceptions\UserNotFoundException;
use App\Http\Resources\SelfUserResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AddressService;
use App\Services\ImageService;
use App\Services\UserService;
use App\Util\stringToModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 *
 */
class UserController extends Controller
{

    public function __construct(
        protected UserService    $userService,
        protected ImageService   $imageService,
        protected AddressService $addressService
    )
    {
    }

    /**
     * Get the authenticated user
     */
    public function me(Request $request): SelfUserResource
    {
        return new SelfUserResource($request->user());
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id)
    {
        $user = $this->userService->getById($id);
        return $this->returnResponse(UserTypes::USER, new UserResource($user));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        try {
            $data = $request->all();

            if (isset($data['address'])) {
                $address = $this->addressService->create(
                    $this->addressService->mapFields($data['address'])
                );
            }

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
                'phone' => $data['phone'] ?? null,
                'location' => $data['location'] ?? null,
                'location_lat_long' => $data['location_lat_long'] ?? null,
                'type_id' => $data['type'] == UserTypes::USER ? 1 : 2,
                'address_id' => $address->id ?? null
            ]);
            $user->save();

            return $this->returnResponse(UserTypes::USER, new UserResource($user));

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
            $user = $request->user();

            if ($request->hasFile('profile_photo')) {

                $file = $request->file('profile_photo');
                $date = Date('Ymdi');
                $extension = $file->getClientOriginalExtension() ?: 'jpeg';
                $filename = "profile_" . $user->id . "_" . $date . "." . $extension;

                // Read file and encode to base64
                $fileData = base64_encode(file_get_contents($file->getRealPath()));
                $image = $this->imageService->processImage($fileData, $filename);
            } else if ($request->has('profile_photo')) {
                // Handle direct base64 string if that's what's being sent
                $file = $request->profile_photo;
                $date = Date('Ymdi');
                $filename = "profile_" . $user->id . "_" . $date . ".jpeg";

                $image = $this->imageService->processImage($file, $filename);
            } else {
                return $this->returnErrorResponse("No profile photo provided", "No file uploaded");
            }

            $user = $this->userService->setProfileImage($user->id, $image);

            return $this->returnResponse('user', new UserResource($user));

        } catch (\Exception $e) {
            \Log::error("Error uploading profile photo", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'user_id' => $request->user()->id ?? 'unknown'
            ]);

            return $this->returnErrorResponse($e->getMessage(), "Error uploading profile photo");
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

                switch ($fieldName) {
                    case 'styles':
                        $this->userService->updateStyles($user, $fieldVal);
                        break;
                    case 'tattoos':
                        $this->userService->updateTattoos($user, $fieldVal);
                        break;
                    case 'artists':
                        $this->userService->updateArtists($user, $fieldVal);
                        break;
                }
            }
            $user->save();
        } catch (\Exception $e) {
            return $this->returnErrorResponse($e->getMessage());
        }

        return $this->returnResponse(UserTypes::USER, new UserResource($user));
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

        return $this->returnResponse(UserTypes::USER, new UserResource($user));
    }

    /**
     * @return void
     */
    public function delete()
    {

    }
}
