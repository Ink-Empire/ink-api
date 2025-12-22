<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    use PasswordValidationRules;

    /**
     * Handle an incoming new password request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
        ]);

        // Check if the new password matches any of the last 5 passwords
        $user = User::where('email', $request->email)->first();

        if ($user) {
            $lastPasswords = $user->passwords()
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            foreach ($lastPasswords as $oldPassword) {
                if (Hash::check($request->password, $oldPassword->password)) {
                    throw ValidationException::withMessages([
                        'password' => ['You cannot reuse any of your last 5 passwords.'],
                    ]);
                }
            }
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Store the new password in history
                $user->passwords()->create([
                    'password' => Hash::make($request->password),
                ]);

                event(new PasswordReset($user));
            }
        );

        if ($status != Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['status' => __($status)]);
    }
}
