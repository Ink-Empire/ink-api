<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CorrectEmailRequest;
use App\Http\Resources\EmailVerificationResource;
use App\Services\EmailCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailCorrectionController extends Controller
{
    public function __construct(protected EmailCorrectionService $emailCorrectionService)
    {
    }

    /**
     * Correct the address on an unverified account and re-send verification.
     */
    public function update(CorrectEmailRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        try {
            $user = $this->emailCorrectionService->correct(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
                $request->string('new_email')->toString(),
            );
        } catch (ValidationException $e) {
            RateLimiter::hit($request->throttleKey());

            throw $e;
        }

        RateLimiter::clear($request->throttleKey());

        return response()->json([
            'message' => 'Email updated. Check your inbox for a new verification link.',
            'verification' => new EmailVerificationResource($user),
        ]);
    }
}
