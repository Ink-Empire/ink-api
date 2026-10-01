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

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                // Runs only after the token is validated. Checking history
                // first let a caller with no token learn both that an address
                // is registered and that a guessed password was recently used
                // on it.
                $this->ensurePasswordNotReused($user, $request->password);

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

        // One message for an unknown address, a bad token and an expired one,
        // so a reset link cannot be used to find out which addresses are
        // registered.
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return response()->json(['status' => __($status)]);
    }

    /**
     * @throws ValidationException
     */
    protected function ensurePasswordNotReused(User $user, string $password): void
    {
        $lastPasswords = $user->passwords()
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        foreach ($lastPasswords as $oldPassword) {
            if (Hash::check($password, $oldPassword->password)) {
                throw ValidationException::withMessages([
                    'password' => ['You cannot reuse any of your last 5 passwords.'],
                ]);
            }
        }
    }
}
