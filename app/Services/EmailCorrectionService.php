<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Lets a user who mistyped their address at registration point their account
 * at the correct one and receive a fresh verification link.
 *
 * Before this existed the only recovery was registering again, which stranded
 * the first attempt holding an email, username and slug nobody could reclaim.
 */
class EmailCorrectionService
{
    /**
     * Successful corrections allowed per account per decay window.
     *
     * Each success mails an address the caller chose, so the credential check
     * alone is not enough to stop the endpoint being used as a slow relay.
     */
    protected const MAX_SENDS = 3;

    protected const SEND_DECAY_SECONDS = 3600;

    /**
     * Point an unverified account at a new address and re-send verification.
     *
     * @throws ValidationException
     */
    public function correct(string $currentEmail, string $password, string $newEmail): User
    {
        $user = User::where('email', $currentEmail)->first();

        // One message for "no such account" and "wrong password" so the
        // endpoint cannot be used to find out which addresses are registered.
        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['This account is already verified. Sign in and change your email from account settings.'],
            ]);
        }

        $sendKey = $this->sendKey($user);

        if (RateLimiter::tooManyAttempts($sendKey, self::MAX_SENDS)) {
            throw ValidationException::withMessages([
                'new_email' => ['Too many email changes. Try again later or contact support.'],
            ]);
        }

        // The verification link's hash is sha1 of the address it was issued
        // for, so writing a new address here is what invalidates every link
        // already sent to the old one. VerifyEmailController rejects them.
        $user->forceFill([
            'email' => $newEmail,
            'email_verified_at' => null,
            'is_email_verified' => false,
        ])->save();

        RateLimiter::hit($sendKey, self::SEND_DECAY_SECONDS);

        $user->sendEmailVerificationNotification();

        return $user;
    }

    protected function sendKey(User $user): string
    {
        return 'email-correction:' . $user->getKey();
    }
}
