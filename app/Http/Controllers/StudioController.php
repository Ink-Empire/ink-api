<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudioResource;
use App\Models\Studio;
use App\Services\AddressService;
use App\Services\StudioService;
use App\Services\UserService;
use Illuminate\Http\Request;

class StudioController extends Controller
{
    public function __construct(
        protected AddressService $addressService,
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
            $data = $request->get('payload');

            $studioData = $data['studioData'];
            $addressData = $data['address'];
            $user_id = $data['user_id'];

            $studioAddress = $this->addressService->create($addressData);

            if ($studioAddress) {
                $studio = new Studio([
                    'name' => $studioData['name'],
                    'email' => $studioData['email'],
                    'phone' => $studioData['phone'] ?? null,
                ]);

                //attach address to studio
                $studio->address_id = $studioAddress->id;
                $studio->save();
            }

            $user = $this->userService->getById($user_id);

            if ($user) {
                //primary studio id -- TODO possibilities on attaching user to many if its relevant
                $user->studio_id = $studio->id;
                $user->save();
            }

            return $this->returnResponse('studio', new StudioResource($studio));

        } catch (\Exception $e) {
            \Log::error("Unable to create user", [
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

        if (isset($data['days'])) {
            $this->studioService->setBusinessDays($data, $studio);
            $studio->load('business_hours');
        }

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
}
