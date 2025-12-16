<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\StudioResource;
use App\Models\Studio;
use App\Models\User;
use App\Services\AddressService;
use App\Services\ImageService;
use App\Services\StudioService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioController extends Controller
{
    public function __construct(
        protected AddressService $addressService,
        protected ImageService   $imageService,
        protected StudioService  $studioService,
        protected UserService    $userService,
    )
    {
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get()
    {
        $studios = $this->studioService->get();

        return $this->returnResponse('studios', StudioResource::collection($studios));
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id)
    {
        $studio = $this->studioService->getById($id);
        return $this->returnResponse('studio', new StudioResource($studio));
    }

    //TODO create custom request
    public function create(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $data = $request->all();

            $address = null;

//            if ($data['address']) {
//                $address = $this->addressService->create(
//                    [
//                        'address1' => $data['address']['address1'],
//                        'address2' => $data['address']['address2'] ?? null,
//                        'city' => $data['address']['city'],
//                        'state' => $data['address']['state'],
//                        'postal_code' => $data['address']['postal_code'],
//                        'country_code' => $data['address']['country_code'] ?? "US"
//                    ]
//                );
//            }

            $studio = new Studio([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
                'email' => $data['email'] ?? null,
                'about' => $data['about'] ?? null,
                'phone' => $data['phone'] ?? null,
                'location' => $data['location'] ?? null,
                'location_lat_long' => $data['location_lat_long'] ?? null,
                'address_id' => $address->id ?? null,
                'owner_id' => $data['owner_id'] ?? null,
            ]);

            $studio->save();

            return $this->returnResponse('studio', new StudioResource($studio));

        } catch (\Exception $e) {
            \Log::error("Unable to create studio", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();
        $studio = $this->studioService->getById($id);

        foreach ($data as $fieldName => $fieldVal) {
            if (in_array($fieldName, $studio->getFillable())) {
                $studio->{$fieldName} = $fieldVal;
            }

            switch ($fieldName) {
                case 'days':
                    $this->studioService->setBusinessDays($data, $studio);
                    $studio->load('business_hours');
                    break;
                case 'styles':
                    $this->studioService->updateStyles($studio, $fieldVal);
                    break;
                case 'tattoos':
                    $this->userService->updateTattoos($studio, $fieldVal);
                    break;
                case 'artists':
                    $this->userService->updateArtists($studio, $fieldVal);
                    break;
            }
        }
        $studio->save();

        return $this->returnResponse('studio', new StudioResource($studio));

    }

    public function updateBusinessHours(Request $request, $id)
    {
        $data = $request->all();

        $studio = $this->studioService->getById($id);

        if (isset($data['days'])) {
            $this->studioService->setBusinessDays($data, $studio);
            $studio->load('business_hours');
        }

        return $this->returnResponse('studio', new StudioResource($studio));
    }

    public function uploadImage(Request $request, $id): JsonResponse
    {
        try {
            $studio = $this->studioService->getById($id);

            if (!$studio) {
                return $this->returnErrorResponse('Studio not found');
            }

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $date = date('Ymdi');
                $extension = $file->getClientOriginalExtension() ?: 'jpeg';
                $filename = "studio_" . $id . "_" . $date . "." . $extension;

                $fileData = base64_encode(file_get_contents($file->getRealPath()));
                $image = $this->imageService->processImage($fileData, $filename);
            } elseif ($request->has('image')) {
                $file = $request->image;
                $date = date('Ymdi');
                $filename = "studio_" . $id . "_" . $date . ".jpeg";

                $image = $this->imageService->processImage($file, $filename);
            } else {
                return $this->returnErrorResponse('No image provided');
            }

            $studio = $this->studioService->setStudioImage($id, $image);

            return $this->returnResponse('studio', new StudioResource($studio));

        } catch (\Exception $e) {
            \Log::error('Unable to upload studio image', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }
}
