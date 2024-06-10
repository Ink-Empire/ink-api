<?php

namespace App\Http\Controllers;

use App\Http\Requests\TattooCreateRequest;
use App\Http\Resources\Elastic\Primary\ArtistResource;
use App\Http\Resources\Elastic\Primary\TattooResource;
use App\Models\Style;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\User;
use App\Services\ImageService;
use App\Services\TattooService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TattooController extends Controller
{

    const RELATIONSHIPS = [
        'styles' => Style::class,
    ];

    public function __construct(
        protected TattooService  $tattooService,
        protected ImageService   $imageService,
        protected UserService    $userService
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

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        try {
            $data = $request->all();

            $user = $this->userService->getById($data['user_id']);

            $file = $request->get('file');
            $tags = $data['tags'] ?? null;
            $styles = $data['styles'] ?? null;

            if ($file) {
                $date = Date('Ymdi');
                $filename = "tattoo_" . $user->id . "_" . $date . ".jpeg";
                $image = $this->imageService->processImage($file, $filename);
            }

            $tattoo = new Tattoo([
                'description' => $data['description'],
                'title' => $data['title'] ?? $filename ?? null,
                'artist_id' => $user->id,
                'studio_id' => $data['studio_id'] ?? null,
                'primary_style_id' => $styles[0] ?? null, //TODO decide how we let the user pick #1
                'primary_image_id' => $image->id ?? null,
            ]);

            $tattoo->save();

            if (!empty($styles)) {
                $styles = Style::whereIn('id', explode(",", $styles))->get();
            }

            $tattoo->styles()->attach($styles);

            if (!empty($tags)) {
                $tags = explode(",", $tags);

                foreach ($tags as $tag) {
                    $tag = new Tag([
                        'tattoo_id' => $tattoo->id,
                        'tag' => $tag
                    ]);

                    $tag->save();
                }
            }

            \Log::info("created tattoo", ['tattoo' => $tattoo->id]);

            $tattoo->searchable();

            \Log::info("indexed tattoo", ['tattoo' => $tattoo->id]);


            return $this->returnResponse('tattoo', new TattooResource($tattoo));

        } catch (\Exception $e) {
            \Log::error("Unable to create tattoo", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

}
