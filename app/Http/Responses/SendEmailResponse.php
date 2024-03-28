<?php

namespace App\Http\Responses;


use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SendEmailResponse implements Responsable
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function toResponse($request) : JsonResponse
    {
        return response()->json([
                'message' => 'email verification sent',
            ], 200);
    }
}
