<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  $request
     * @return JsonResponse
     */
    public function toResponse($request): JsonResponse
    {
        $email = $request->get('payload')['email'];

        $user = User::where('email', $email)->first();
        return response()->json([
            'message' => 'Registration successful, verify your email address',
            "token" => $user->createToken($request->email)->plainTextToken,
        ], 200);
    }
}
