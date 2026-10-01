<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user && !$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        // One response for an unknown address, an already verified one and a
        // link actually sent. Wording the unknown case carefully is not enough
        // on its own; three outcomes with three bodies is still an oracle.
        return response()->json([
            'message' => 'If that email needs verifying, a link has been sent.',
        ]);
    }
}
