<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudioResource;
use App\Http\Resources\UserResource;
use App\Models\Studio;
use App\Services\StudioService;
use App\Services\UserService;
use Illuminate\Http\Request;

class StudioController extends Controller
{
    public function __construct(
        protected StudioService $studioService,
        protected UserService   $userService
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

    public function create(Request $request)
    {
        try {
            $data = $request->get('payload');
            $studio = new Studio([
                'name' => $data['payload']['name'],
                'email' => $data['payload']['email'],
                'phone' => $data['payload']['phone'] ?? null,
                'location' => $data['payload']['location'] ?? null,
            ]);

            $studio->save();

            $user = $this->userService->getById($data['id']);

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

}
