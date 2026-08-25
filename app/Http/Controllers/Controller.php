<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Whether this request asked to see demo data.
     *
     * The demo toggle is the only switch. This used to read the viewer's
     * is_demo column instead, which forced demo results on for every account
     * carrying the flag and left their toggle doing nothing.
     */
    protected function wantsDemoData(Request $request): bool
    {
        return $request->boolean('include_demo');
    }

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
