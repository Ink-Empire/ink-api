<?php

/**
 * Recovery for an address mistyped at registration.
 *
 * An unverified user cannot pass auth middleware and every resend goes to the
 * address they got wrong, so without this endpoint their only option is to
 * register again and strand the first account.
 */

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();
    Notification::fake();

    // Rate limiter state lives in the array cache for the whole process.
    Cache::flush();

    $this->user = User::factory()->create([
        'email' => 'interscoperecordlabelsp@gmail.com',
        'password' => bcrypt('correct-horse'),
        'email_verified_at' => null,
        'is_email_verified' => false,
    ]);
});

function correctEmail(array $overrides = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/email/correct', array_merge([
        'email' => 'interscoperecordlabelsp@gmail.com',
        'password' => 'correct-horse',
        'new_email' => 'interscoperecordlabelps@gmail.com',
    ], $overrides));
}

test('corrects the address on an unverified account and sends a fresh link', function () {
    $response = correctEmail();

    $response->assertOk()
        ->assertJsonPath('verification.email', 'interscoperecordlabelps@gmail.com')
        ->assertJsonPath('verification.requires_verification', true);

    $this->user->refresh();

    expect($this->user->email)->toBe('interscoperecordlabelps@gmail.com')
        ->and($this->user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($this->user, VerifyEmailNotification::class);
});

test('rejects a wrong password and leaves the address alone', function () {
    correctEmail(['password' => 'not-the-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($this->user->fresh()->email)->toBe('interscoperecordlabelsp@gmail.com');

    Notification::assertNothingSent();
});

test('answers an unknown address exactly as it answers a wrong password', function () {
    $unknown = correctEmail(['email' => 'nobody@example.com']);
    $wrongPassword = correctEmail(['password' => 'not-the-password']);

    expect($unknown->status())->toBe($wrongPassword->status())
        ->and($unknown->json('errors.email'))->toBe($wrongPassword->json('errors.email'));
});

test('refuses to change the address of an account that is already verified', function () {
    $this->user->forceFill([
        'email_verified_at' => now(),
        'is_email_verified' => true,
    ])->save();

    correctEmail()
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($this->user->fresh()->email)->toBe('interscoperecordlabelsp@gmail.com');

    Notification::assertNothingSent();
});

test('rejects a new address already registered to another account', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    correctEmail(['new_email' => 'taken@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_email');

    expect($this->user->fresh()->email)->toBe('interscoperecordlabelsp@gmail.com');
});

test('rejects the address the account already holds', function () {
    correctEmail(['new_email' => 'interscoperecordlabelsp@gmail.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_email');
});

test('invalidates the verification link already sent to the old address', function () {
    $oldLink = URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $this->user->id, 'hash' => sha1('interscoperecordlabelsp@gmail.com')]
    );

    correctEmail()->assertOk();

    $this->getJson($oldLink)->assertStatus(403);

    expect($this->user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('throttles repeated failed attempts', function () {
    foreach (range(1, 5) as $ignored) {
        correctEmail(['password' => 'not-the-password'])->assertStatus(422);
    }

    correctEmail()
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($this->user->fresh()->email)->toBe('interscoperecordlabelsp@gmail.com');
});

test('caps how many times one account can be pointed at a new address', function () {
    correctEmail(['new_email' => 'first@example.com'])->assertOk();

    $this->postJson('/api/email/correct', [
        'email' => 'first@example.com',
        'password' => 'correct-horse',
        'new_email' => 'second@example.com',
    ])->assertOk();

    $this->postJson('/api/email/correct', [
        'email' => 'second@example.com',
        'password' => 'correct-horse',
        'new_email' => 'third@example.com',
    ])->assertOk();

    $this->postJson('/api/email/correct', [
        'email' => 'third@example.com',
        'password' => 'correct-horse',
        'new_email' => 'fourth@example.com',
    ])->assertStatus(422)->assertJsonValidationErrors('new_email');

    expect($this->user->fresh()->email)->toBe('third@example.com');
});
