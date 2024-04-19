<?php

namespace App\Http\Controllers;

use App\Http\Resources\Elastic\Primary\ArtistResource;
use App\Http\Resources\Elastic\Primary\TattooResource;
use App\Services\TattooService;
use Illuminate\Http\JsonResponse;

class TattooController extends Controller
{
    public function __construct(
        protected TattooService  $tattooService,
    )
    {
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get()
    {
        //eventually perhaps replaced with an ES call
        $tattoos = $this->tattooService->get();

        return $this->returnResponse('tattoos', TattooResource::collection($tattoos));
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id): JsonResponse
    {
        $tattoo = $this->tattooService->getById($id);

        return $this->returnResponse('tattoo', new TattooResource($tattoo));
    }

}
