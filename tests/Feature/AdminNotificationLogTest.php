<?php

/**
 * The admin screen that answers "did this person get their email, and when".
 */

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\NotificationLog\Models\NotificationLogItem;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();

    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
});

function logItemFor(User $user, string $type = 'App\\Notifications\\WelcomeNotification'): NotificationLogItem
{
    return NotificationLogItem::create([
        'notification_type' => $type,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'channel' => 'mail',
        'fingerprint' => '',
    ]);
}

it('lists what was sent, with the recipient resolved', function () {
    $artist = User::factory()->asArtist()->create([
        'name' => 'Sylvia Barlow',
        'email' => 'sylvia@example.com',
    ]);

    logItemFor($artist);

    $body = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/notification-logs')
        ->assertOk()
        ->json();

    expect($body['total'])->toBe(1)
        ->and($body['data'][0]['recipient_email'])->toBe('sylvia@example.com')
        ->and($body['data'][0]['recipient_name'])->toBe('Sylvia Barlow');
});

/**
 * The class name is what an admin recognises; the namespace is noise in a
 * table but kept alongside so it can still be identified precisely.
 */
it('shows the short notification name and the full class', function () {
    logItemFor(User::factory()->create(), 'App\\Notifications\\InboundEmailReceiptNotification');

    $row = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/notification-logs')
        ->json('data.0');

    expect($row['notification_type'])->toBe('InboundEmailReceiptNotification')
        ->and($row['notification_class'])->toBe('App\\Notifications\\InboundEmailReceiptNotification');
});

it('finds what was sent to an address', function () {
    $wanted = User::factory()->create(['email' => 'wanted@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);

    logItemFor($wanted);
    logItemFor($other);

    $body = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/notification-logs?filter='.urlencode(json_encode(['q' => 'wanted@example.com'])))
        ->json();

    expect($body['total'])->toBe(1)
        ->and($body['data'][0]['recipient_email'])->toBe('wanted@example.com');
});

it('finds by notification type too', function () {
    logItemFor(User::factory()->create(), 'App\\Notifications\\VerifyEmailNotification');
    logItemFor(User::factory()->create(), 'App\\Notifications\\WelcomeNotification');

    $body = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/notification-logs?filter='.urlencode(json_encode(['q' => 'VerifyEmail'])))
        ->json();

    expect($body['total'])->toBe(1)
        ->and($body['data'][0]['notification_type'])->toBe('VerifyEmailNotification');
});

/**
 * A deleted user must not take the record of what was sent to them with it,
 * or the screen quietly loses history.
 */
it('still lists a row whose recipient is gone', function () {
    $user = User::factory()->create();
    logItemFor($user);
    $user->forceDelete();

    $row = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/notification-logs')
        ->assertOk()
        ->json('data.0');

    expect($row['recipient_email'])->toBeNull()
        ->and($row['notification_type'])->toBe('WelcomeNotification');
});

it('sorts newest first by default', function () {
    $first = logItemFor(User::factory()->create(), 'App\\Notifications\\WelcomeNotification');
    $second = logItemFor(User::factory()->create(), 'App\\Notifications\\VerifyEmailNotification');

    $body = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/notification-logs')
        ->json();

    expect($body['data'][0]['id'])->toBe($second->id)
        ->and($body['data'][1]['id'])->toBe($first->id);
});

/**
 * An unknown sort column would otherwise reach the database as SQL.
 */
it('ignores a sort column that is not allowed', function () {
    logItemFor(User::factory()->create());

    $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/notification-logs?sort=fingerprint')
        ->assertOk();
});

it('is closed to non admins', function () {
    $artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);

    $this->actingAs($artist, 'sanctum')
        ->getJson('/api/admin/notification-logs')
        ->assertForbidden();
});
