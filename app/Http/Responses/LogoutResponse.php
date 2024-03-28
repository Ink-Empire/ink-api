<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class LogoutResponse implements LogoutResponseContract
{
    /**
     * @param  $request
     * @return JsonResponse
     */
    public function toResponse($request)
    {
        return response()->json(["user logged out successfully"], 200);
    }
}
