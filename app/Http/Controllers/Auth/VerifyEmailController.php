<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendWelcomeNotification;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(Request $request, $id, $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            // Generate token for already verified users too
            $user->tokens()->delete();
            $token = $user->createToken('authToken')->plainTextToken;

            return response()->json([
                'message' => 'Email already verified.',
                'already_verified' => true,
                'token' => $token,
                'user' => $user,
                'redirect_url' => $user->type_id === 2 ? '/dashboard' : '/tattoos',
            ]);
        }

        if ($user->markEmailAsVerified()) {
            // Also set our custom is_email_verified boolean for easier filtering
            $user->update(['is_email_verified' => true]);
            event(new Verified($user));

            // Send welcome email now that they're verified
            SendWelcomeNotification::dispatch($user->id);
        }

        // Generate a new token for the user
        $user->tokens()->delete();
        $token = $user->createToken('authToken')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully.',
            'token' => $token,
            'user' => $user,
            'redirect_url' => $user->type_id === 2 ? '/dashboard' : '/tattoos',
        ]);
    }
}
