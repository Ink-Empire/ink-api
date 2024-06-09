<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use App\Services\UserService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected SearchService $searchService,
        protected UserService $userService
    )
    {}

    public function getInitialSearch(Request $request)
    {
        //home page initial search will return tattoo results

        //either tattoos in saved styles OR artists user has saved OR artists near location, sorted by new
        $response = $this->searchService->initialUserResults($request->get('user_id'));

        return $this->returnElasticResponse($response['response']);
    }

    public function index(Request $request)
    {
        $model = $request->input('model');
        $filters = $request->except('model');

        if ($model == 'tattoo') {
           $response = $this->checkResponse($this->searchService->search_tattoo($filters), $model);
        } else {
            $response = $this->checkResponse($this->searchService->search_artist($filters), $model);
        }

        return $this->returnElasticResponse($response);
    }

    private function checkResponse($response, $model)
    {
        if ($response['response']->count() == 0 && !isset($params['search_again'])) {
            //instead we will need to formulate a popularity system and use GeoSort here, once we have more data
            $params['artist_near_me'] = false;
            $params['search_again'] = true;

            $method = "search" . "_" . $model;

            return $this->searchService->{$method}($params);
        } else {
            return $response['response'];
        }
    }
}
