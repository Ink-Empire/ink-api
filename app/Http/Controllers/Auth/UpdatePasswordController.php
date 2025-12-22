<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UpdatePasswordController extends Controller
{
    use PasswordValidationRules;

    /**
     * Update the authenticated user's password.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => $this->passwordRules(),
        ]);

        $user = $request->user();

        // Verify the current password
        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Check if the new password matches any of the last 5 passwords
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

        // Update the password
        $user->forceFill([
            'password' => Hash::make($request->password),
        ])->save();

        // Store the new password in history
        $user->passwords()->create([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
