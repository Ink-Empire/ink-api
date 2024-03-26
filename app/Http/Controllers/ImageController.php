<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudioResource;
use App\Http\Resources\UserResource;
use App\Services\ImageService;
use App\Services\StudioService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ImageController extends Controller
{
    public function __construct(
        protected ImageService  $imageService,
        protected UserService   $userService,
        protected StudioService $studioService
    )
    {
    }

    /**
     * @param Request $request
     */
    public function upload(Request $request): JsonResponse|Response
    {
        try {
            $data = $request->all();

            $file = $request->get('my_file');

            $id = $request->get('id');
            $type = $request->get('type'); //user, studio

            $date = Date('Ymdi');
            $filename = "profile_" . $id . "_" . $date . ".jpeg";

            $image = $this->imageService->processImage($file, $filename);

            if ($image) {
                return $this->setProfileImage($type, $id, $image);
            }

        } catch (\Exception $e) {
            $error = "Error: Unable to set profile image for type $type";
            \Log::error($error, [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage(), $error);
        }
    }

    private function setProfileImage($type, $id, $image)
    {
        switch ($type) {
            case 'user': //artists can also use user service during registration for upload
                $user = $this->userService->setProfileImage($id, $image);
                return $this->returnResponse('user', new UserResource($user));
            case 'studio':
                $studio = $this->studioService->setStudioImage($id, $image);
                return $this->returnResponse('studio', new StudioResource($studio));
        }
    }
}
