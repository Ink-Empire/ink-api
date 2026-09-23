<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CorrectEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'new_email' => ['required', 'string', 'email', 'max:255', 'different:email', 'unique:users,email'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_email.different' => 'That is the address already on the account.',
            'new_email.unique' => 'That email is already registered to another account.',
        ];
    }

    /**
     * Throttle credential attempts the same way login does, so this endpoint
     * cannot be used to guess passwords for unverified accounts.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (!RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate('email-correct|' . Str::lower($this->string('email')) . '|' . $this->ip());
    }
}
