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
use App\Services\TattooService;
use App\Services\TagService;
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
        protected TagService $tattooTagService
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

        $response = $this->tattooService->search($params);

        //if response.items is empty, re-do search without distance filters and return an error message
        if (count($response["response"]) == 0) {
            $response = $this->tattooService->search($params);
            $response['none_found'] = "No results found for your search, here are some suggestions: \n" .
                "1. Try searching for a different tattoo style or artist.\n" .
                "2. Check your spelling and try again.\n" .
                "3. Broaden your search radius to find more results.";
        }

        return $this->returnElasticResponse($response);
    }

    /**
     * Generate AI tags for a specific tattoo
     */
    public function generateTags(Request $request, $id): JsonResponse
    {
        try {
            $tattoo = Tattoo::with(['images', 'tags'])->find($id);

            if (!$tattoo) {
                return $this->returnErrorResponse('Tattoo not found', 'The specified tattoo does not exist.');
            }

            if ($tattoo->images->count() === 0) {
                return $this->returnErrorResponse('No images', 'This tattoo has no images to analyze.');
            }

            // Check if user owns this tattoo or is admin
            $user = $request->user();
            if ($user->id !== $tattoo->artist_id && $user->type_id !== 1) { // Assuming type_id 1 is admin
                return $this->returnErrorResponse('Unauthorized', 'You can only generate tags for your own tattoos.');
            }

            $regenerate = $request->boolean('regenerate', false);

            $tags = $regenerate
                ? $this->tattooTagService->regenerateTagsForTattoo($tattoo)
                : $this->tattooTagService->generateTagsForTattoo($tattoo);

            if (count($tags) === 0 && !$regenerate && $tattoo->tags->count() > 0) {
                return $this->returnResponse('tags', [
                    'message' => 'Tattoo already has tags. Use regenerate=true to create new ones.',
                    'existing_tags' => $tattoo->tags->pluck('tag')->toArray()
                ]);
            }

            // Re-index the tattoo for search
            $tattoo->searchable();

            return $this->returnResponse('tags', [
                'message' => 'Tags generated successfully',
                'tags' => collect($tags)->pluck('tag')->toArray(),
                'count' => count($tags)
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate tags for tattoo', [
                'tattoo_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->returnErrorResponse('Tag generation failed', $e->getMessage());
        }
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

                // Handle style IDs
                $styleIds = [];
                if ($request->has('style_ids')) {
                    $styleIds = json_decode($request->input('style_ids'), true);
                    if (!is_array($styleIds)) {
                        $styleIds = [];
                    }
                }

                // Get primary style from explicit field or first style
                $primaryStyleId = $request->input('primary_style_id')
                    ?: (!empty($styleIds) ? $styleIds[0] : null);

                $tattoo = Tattoo::create([
                    'artist_id' => $user->id,
                    'primary_image_id' => $primaryImage->id,
                    'studio_id' => $user->studio_id ?? null,
                    'description' => $request->input('description'),
                    'placement' => $request->input('placement'),
                    'primary_style_id' => $primaryStyleId,
                ]);

                // Attach ALL images to the pivot table (including the primary image)
                $imageIds = collect($images)->pluck('id')->toArray();
                
                // Ensure primary image is definitely included in the pivot table
                if (!in_array($primaryImage->id, $imageIds)) {
                    $imageIds[] = $primaryImage->id;
                }
                
                $tattoo->images()->attach($imageIds);

                // Attach all selected styles to the tattoo
                if (!empty($styleIds)) {
                    $tattoo->styles()->attach($styleIds);
                }

                // Generate AI tags for the tattoo images
                try {
                    $tattoo->load('images');
                    $this->tattooTagService->generateTagsForTattoo($tattoo);
                    \Log::info("AI tags generated for tattoo", ['tattoo_id' => $tattoo->id]);
                } catch (\Exception $e) {
                    // Don't fail the entire request if tag generation fails
                    \Log::error("Failed to generate AI tags for tattoo", [
                        'tattoo_id' => $tattoo->id,
                        'error' => $e->getMessage()
                    ]);
                }

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
