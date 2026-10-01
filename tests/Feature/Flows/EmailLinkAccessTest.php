<?php

/**
 * The subscribe and unsubscribe links are clicked straight out of an email, so
 * they cannot carry an X-App-Token header. They sat behind VerifyAppToken and
 * returned 401 to everybody, which left every mail footer's unsubscribe link
 * dead. The expiring signature on the URL is what authorises them.
 */

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();
});

test('a signed subscribe link works without an app token', function () {
    $user = User::factory()->create(['is_subscribed' => false]);

    $this->get(URL::signedRoute('subscribe', ['user' => $user->id], now()->addDays(30)))
        ->assertRedirectContains('subscribed=true');

    expect($user->fresh()->is_subscribed)->toBeTrue();
});

test('a signed unsubscribe link works without an app token', function () {
    $user = User::factory()->create(['email_unsubscribed' => false]);

    $this->get(URL::signedRoute('unsubscribe', ['user' => $user->id], now()->addDays(30)))
        ->assertRedirectContains('success=true');

    expect($user->fresh()->email_unsubscribed)->toBeTrue();
});

test('a tampered signature changes nothing', function () {
    $user = User::factory()->create(['email_unsubscribed' => false]);

    $this->get('/api/unsubscribe?user='.$user->id.'&expires=9999999999&signature=deadbeef')
        ->assertRedirectContains('error=invalid_link');

    expect($user->fresh()->email_unsubscribed)->toBeFalse();
});

test('an expired signature changes nothing', function () {
    $user = User::factory()->create(['email_unsubscribed' => false]);

    $expired = URL::signedRoute('unsubscribe', ['user' => $user->id], now()->subMinute());

    $this->get($expired)->assertRedirectContains('error=invalid_link');

    expect($user->fresh()->email_unsubscribed)->toBeFalse();
});
