<?php

namespace App\Http\Controllers;

use App\Enums\UserRelationships;
use App\Enums\UserTypes;
use App\Http\Resources\SelfUserResource;
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
    public function updateFavorite(Request $request, $type)
    {
        $ids = collect($request->get('ids'))->toArray();
        $user = $request->user();

        //get relationship to user from class name
        $relationship = UserRelationships::getRelationship($type);

        //existing favorites
        $favorites = $user->{$relationship}()->pluck('artist_id')->toArray();

        //find any that are in both array
        $existingIds = array_intersect($ids, $favorites);
        //detach them if they've been sent again
        $user->{$relationship}()->detach($existingIds);

        //sync the new ones
        $ids = array_diff($ids, $existingIds);
        $user->{$relationship}()->syncWithoutDetaching($ids);

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
