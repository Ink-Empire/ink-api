<?php

/**
 * Account existence disclosure on the credential and mail-sending surfaces.
 *
 * Signup availability is allowed to say whether an address is taken. Anything
 * that checks a credential or mails an address the caller named must answer
 * the same way whether or not the account exists, so these endpoints cannot be
 * used to work out who has an account. See the rule in CLAUDE.md.
 */

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();
    Notification::fake();

    // Rate limiter state lives in the array cache for the whole process.
    Cache::flush();

    $this->user = User::factory()->create([
        'email' => 'held@example.com',
        'username' => 'heldaccount',
        'password' => bcrypt('correct-horse'),
        'email_verified_at' => now(),
        'is_email_verified' => true,
    ]);
});

test('forgot password answers an unknown address exactly as a registered one', function () {
    $known = $this->postJson('/api/forgot-password', ['email' => 'held@example.com']);
    $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toBe($known->json());
});

test('forgot password answers an unknown username exactly as an unknown address', function () {
    $unknownUsername = $this->postJson('/api/forgot-password', ['email' => 'nosuchuser']);
    $unknownEmail = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

    expect($unknownUsername->status())->toBe($unknownEmail->status())
        ->and($unknownUsername->json())->toBe($unknownEmail->json());
});

test('forgot password still sends a link to a registered address', function () {
    $this->postJson('/api/forgot-password', ['email' => 'held@example.com'])->assertOk();

    Notification::assertSentTo($this->user, ResetPasswordNotification::class);
});

test('forgot password sends nothing for an unknown address', function () {
    $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

    Notification::assertNothingSent();
});

test('reset password answers an unknown address exactly as a bad token', function () {
    $unknown = $this->postJson('/api/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'nobody@example.com',
        'password' => 'Str0ng-New-Pass!',
        'password_confirmation' => 'Str0ng-New-Pass!',
    ]);

    $badToken = $this->postJson('/api/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'held@example.com',
        'password' => 'Str0ng-New-Pass!',
        'password_confirmation' => 'Str0ng-New-Pass!',
    ]);

    expect($unknown->status())->toBe($badToken->status())
        ->and($unknown->json('errors'))->toBe($badToken->json('errors'));
});

test('reset password does not reveal password history without a valid token', function () {
    $this->user->passwords()->create(['password' => Hash::make('previously-used')]);

    $reused = $this->postJson('/api/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'held@example.com',
        'password' => 'previously-used',
        'password_confirmation' => 'previously-used',
    ]);

    $fresh = $this->postJson('/api/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'held@example.com',
        'password' => 'never-used-before',
        'password_confirmation' => 'never-used-before',
    ]);

    expect($reused->status())->toBe($fresh->status())
        ->and($reused->json('errors'))->toBe($fresh->json('errors'));
});

test('reset password still rejects a reused password when the token is valid', function () {
    $this->user->passwords()->create(['password' => Hash::make('previously-used')]);

    $token = Password::createToken($this->user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'held@example.com',
        'password' => 'previously-used',
        'password_confirmation' => 'previously-used',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(Hash::check('correct-horse', $this->user->fresh()->password))->toBeTrue();
});

test('reset password succeeds with a valid token and an unused password', function () {
    $token = Password::createToken($this->user);

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'held@example.com',
        'password' => 'Str0ng-New-Pass!',
        'password_confirmation' => 'Str0ng-New-Pass!',
    ])->assertOk();

    expect(Hash::check('Str0ng-New-Pass!', $this->user->fresh()->password))->toBeTrue();
});

test('verification notification answers unknown, verified and unverified identically', function () {
    $unverified = User::factory()->create([
        'email' => 'pending@example.com',
        'email_verified_at' => null,
        'is_email_verified' => false,
    ]);

    $unknown = $this->postJson('/api/email/verification-notification', ['email' => 'nobody@example.com']);
    $verified = $this->postJson('/api/email/verification-notification', ['email' => 'held@example.com']);
    $pending = $this->postJson('/api/email/verification-notification', ['email' => $unverified->email]);

    expect($verified->status())->toBe($unknown->status())
        ->and($verified->json())->toBe($unknown->json())
        ->and($pending->status())->toBe($unknown->status())
        ->and($pending->json())->toBe($unknown->json());
});

test('verification notification still sends to an unverified address', function () {
    $unverified = User::factory()->create([
        'email' => 'pending@example.com',
        'email_verified_at' => null,
        'is_email_verified' => false,
    ]);

    $this->postJson('/api/email/verification-notification', ['email' => $unverified->email])->assertOk();

    Notification::assertSentTo($unverified, VerifyEmailNotification::class);
});

test('verification notification sends nothing for a verified or unknown address', function () {
    $this->postJson('/api/email/verification-notification', ['email' => 'held@example.com'])->assertOk();
    $this->postJson('/api/email/verification-notification', ['email' => 'nobody@example.com'])->assertOk();

    Notification::assertNothingSent();
});
