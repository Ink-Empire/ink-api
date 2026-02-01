<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function returnResponse($objectName, $resource)
    {
        return response()->json([$objectName => $resource]);
    }

    protected function returnElasticResponse($data)
    {
        ray($data)->label('Elastic Response')->purple();
        return response()->json($data);
    }

    protected function returnErrorResponse($error, $statusCode = 400)
    {
        // If statusCode is numeric, use it as HTTP status code
        // Otherwise, treat as legacy key name and use 400 as status
        if (is_numeric($statusCode)) {
            return response()->json(['error' => $error, 'message' => $error], (int) $statusCode);
        }
        // Legacy support: string passed as second param means it was a custom key
        return response()->json(['error' => $error, 'message' => $error], 400);
    }
}
