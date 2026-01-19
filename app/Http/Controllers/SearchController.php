<?php

namespace App\Http\Controllers;

use App\Services\TattooService;
use App\Services\ArtistService;
use App\Services\UserService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected TattooService $tattooService,
        protected ArtistService $artistService,
        protected UserService   $userService
    )
    {
    }

    public function getInitialSearch(Request $request)
    {
        //home page initial search will return tattoo results

        //either tattoos in saved styles OR artists user has saved OR artists near location, sorted by new
        $response = $this->tattooService->initialUserResults($request->get('user_id'));

        return $this->returnElasticResponse($response['response']);
    }

    public function index(Request $request)
    {
        $model = $request->input('model');
        $filters = $request->except('model');

        if ($model == 'tattoo') {
            $response = $this->checkResponse($this->tattooService->search_tattoo($filters), $model);
        } else {
            $response = $this->checkResponse($this->artistService->search_artist($filters), $model);
        }

        return $this->returnElasticResponse($response);
    }

    private function checkResponse($response, $model)
    {
        //we dont want to return generic results if we are asking for saved items
        if (!request()->has('saved_artists') && !request()->has('saved_tattoos')) {
            if ($response['response']->count() == 0 && !isset($params['search_again'])) {
                //instead we will need to formulate a popularity system and use GeoSort here, once we have more data
                $params['artist_near_me'] = false;
                $params['search_again'] = true;

                \Log::info("no search results returned, searching again for generic response");

                if ($model == 'tattoo') {
                    return $this->tattooService->search_tattoo($params);
                } else {
                    return $this->artistService->search_artist($params);
                }
            }
        }
        return $response['response'];
    }
}
