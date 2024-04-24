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
        return response()->json($data);
    }

    protected function returnErrorResponse($error, $errorMessage = 'error')
    {
        return response()->json([$errorMessage => $error]);
    }
}
