<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SelfUserResource;
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
            // Upgrade any temporary registration token to a permanent auth token
            $user->tokens()->where('name', 'registration-upload')->update([
                'name' => 'authToken',
                'expires_at' => null,
            ]);

            // Generate token for the browser redirect
            $token = $user->createToken('authToken')->plainTextToken;

            // Load relationships for SelfUserResource
            $user->load([
                'ownedStudio.image',
                'verifiedStudios.image',
                'blockedUsers.image',
                'styles',
                'socialMediaLinks',
                'artists',
                'tattoos',
                'studios',
                'type',
                'image',
            ]);

            return response()->json([
                'message' => 'Email already verified.',
                'already_verified' => true,
                'token' => $token,
                'user' => new SelfUserResource($user),
                'redirect_url' => in_array($user->type_id, [2, 3]) ? '/dashboard' : '/tattoos',
            ]);
        }

        if ($user->markEmailAsVerified()) {
            // Also set our custom is_email_verified boolean for easier filtering
            $user->update(['is_email_verified' => true]);
            event(new Verified($user));

            // Send welcome email now that they're verified
            SendWelcomeNotification::dispatch($user->id);
        }

        // Upgrade any temporary registration token to a permanent auth token
        // so mobile apps polling /users/me continue working
        $user->tokens()->where('name', 'registration-upload')->update([
            'name' => 'authToken',
            'expires_at' => null,
        ]);

        // Generate a new token for the browser redirect
        $token = $user->createToken('authToken')->plainTextToken;

        // Load relationships for SelfUserResource
        $user->load([
            'ownedStudio.image',
            'verifiedStudios.image',
            'blockedUsers.image',
            'styles',
            'socialMediaLinks',
            'artists',
            'tattoos',
            'type',
            'image',
        ]);

        return response()->json([
            'message' => 'Email verified successfully.',
            'token' => $token,
            'user' => new SelfUserResource($user),
            'redirect_url' => in_array($user->type_id, [2, 3]) ? '/dashboard' : '/tattoos',
        ]);
    }
}
