<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string'],
        ]);

        $identifier = $request->email;

        if (str_contains($identifier, '@')) {
            $email = $identifier;
        } else {
            $user = User::where('username', $identifier)->first();

            if (!$user) {
                return response()->json(['status' => __('passwords.sent')]);
            }

            $email = $user->email;
        }

        $status = Password::sendResetLink(
            ['email' => $email]
        );

        // Same response for a delivered link, an unknown address and a
        // throttled retry, so the endpoint cannot be used to find out which
        // addresses are registered. Matches the username branch above.
        if ($status !== Password::RESET_LINK_SENT) {
            Log::info('Password reset link not sent', ['status' => $status]);
        }

        return response()->json(['status' => __('passwords.sent')]);
    }
}
