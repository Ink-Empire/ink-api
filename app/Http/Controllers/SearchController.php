<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(protected SearchService $searchService)
    {
    }

    public function index(Request $request)
    {
        $params = $request->all();

        $response = $this->searchService->search($params);

        if ($response['response']->count() == 0 && !isset($params['search_again'])) {
            //instead we will need to formulate a popularity system and use GeoSort here, once we have more data
            $params['artist_near_me'] = false;
            $params['search_again'] = true;

            $response = $this->searchService->search($params);

        }
        return $this->returnElasticResponse($response);

    }
}
