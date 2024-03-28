<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function returnResponse($objectName, $resource): JsonResponse
    {
        return response()->json([$objectName => $resource]);
    }

    protected function returnErrorResponse($error, $errorMessage = 'error'): JsonResponse
    {
        return response()->json([$errorMessage => $error]);
    }
}
