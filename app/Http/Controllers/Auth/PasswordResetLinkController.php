<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkController extends Controller
{
    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
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

        if ($status != Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['status' => __($status)]);
    }
}
