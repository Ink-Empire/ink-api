<?php

namespace App\Http\Controllers;

use App\Http\Requests\TattooCreateRequest;
use App\Http\Resources\Elastic\Primary\ArtistResource;
use App\Http\Resources\Elastic\Primary\TattooResource;
use App\Models\Artist;
use App\Models\Image;
use App\Models\Style;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\User;
use App\Services\ImageService;
use App\Services\SearchService;
use App\Services\TattooService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TattooController extends Controller
{
    private $filters = [];
    private $search;

    const RELATIONSHIPS = [
        'styles' => Style::class,
    ];

    public function __construct(
        protected TattooService $tattooService,
        protected ImageService  $imageService,
        protected UserService   $userService,
        protected SearchService $searchService
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

    public function search(Request $request): JsonResponse
    {
        $params = $request->all();
        $user = null;

        // If user is authenticated, include user-specific preferences
        if ($request->user()) {
            $user = $request->user();
        }

        $response = $this->searchService->search_tattoo($params, $user);

        //if response.items is empty, re-do search without distance filters and return an error message
        if (count($response["response"]) == 0) {
            $response = $this->searchService->search_tattoo($params, $user);
            $response['none_found'] = "No results found for your search, here are some suggestions: \n" .
                "1. Try searching for a different tattoo style or artist.\n" .
                "2. Check your spelling and try again.\n" .
                "3. Broaden your search radius to find more results.";
        }

        return $this->returnElasticResponse($response);
    }

    public function create(Request $request): JsonResponse|TattooResource
    {
        try {
            $user = $request->user();

            //get the files uploaded, there may be multiple
            $files = $request->file('files');

            //if we just have one file, make it an array
            if (!is_array($files)) {
                $files = [$files];
            }

            $images = $this->tattooService->upload($files, $user);

            if(count($images) > 0) {
               $primaryImage = $images[0];

                $tattoo = Tattoo::create([
                    'artist_id' => $user->id,
                    'primary_image_id' => $primaryImage->id,
                    'studio_id' => $user->studio_id ?? null,
                ]);

                $tattoo->images()->attach(collect($images)->pluck('id'));

                $tattoo->searchable(); //this is likely unnecessary, but we will need to re-index the tattoo
                Artist::find($user->id)->searchable(); //re-index the artist

                return new TattooResource($tattoo);
            } else {
                return $this->returnErrorResponse("No images uploaded", "No files uploaded");
            }

        } catch (\Exception $e) {
            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            //todo figure out how jonathan is returning that object without querying it
            $tattoo = $this->tattooService->getById($id);

            $tags = $data['tags'] ?? null; //TODO properly implement tags

            //TODO idea get AI to analyze image in post-process to add subject/tags
            $styles = $data['styles'] ?? null;

            if(!empty($styles)) {
                $styles = Style::whereIn('id', explode(",", $styles))->get();
                //attach styles to tattoo
                $tattoo->styles()->sync($styles);
            }

            $tattoo->save();


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
