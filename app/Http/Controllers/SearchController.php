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

        return $this->returnElasticResponse($response);
    }
}
