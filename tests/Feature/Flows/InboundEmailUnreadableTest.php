<?php

use App\Models\User;
use App\Notifications\InboundEmailReceiptNotification;
use App\Notifications\InboundEmailUnreadableNotification;
use App\Services\ArtistOnboardingService;
use Illuminate\Support\Facades\Notification;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

/**
 * An inbound batch that produces no usable images used to end in silence.
 * onboard() creates the account before it tries the images, and the temp
 * password it generates only ever reaches the artist through a mail, so a
 * skipped notification left somebody holding an account they did not know
 * about with a password nobody could recover.
 *
 * HEIC is the common way to land here. iPhones shoot it by default and
 * getimagesizefromstring cannot read it.
 */
beforeEach(function () {
    Notification::fake();
});

// A real 1x1 PNG. Inlined rather than generated because the container's GD
// has no JPEG support, so imagejpeg() is undefined here.
const READABLE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function readableAttachment(): array
{
    return ['content' => READABLE_PNG, 'mime' => 'image/png'];
}

function unreadableAttachment(): array
{
    return ['content' => base64_encode('not an image at all'), 'mime' => 'image/jpeg'];
}

test('an artist whose photos could not be read still hears back', function () {
    app(ArtistOnboardingService::class)->onboard(
        'newartist@example.com',
        'New Artist',
        [unreadableAttachment(), unreadableAttachment()],
        'email'
    );

    $artist = User::where('email', 'newartist@example.com')->firstOrFail();

    Notification::assertSentTo($artist, InboundEmailUnreadableNotification::class);
    Notification::assertNotSentTo($artist, InboundEmailReceiptNotification::class);
});

test('a new account gets its credentials even when nothing was readable', function () {
    app(ArtistOnboardingService::class)->onboard(
        'stranded@example.com',
        'Stranded Artist',
        [unreadableAttachment()],
        'email'
    );

    $artist = User::where('email', 'stranded@example.com')->firstOrFail();

    // Without the password the account is unreachable: it exists, the address
    // is taken by the unique constraint, and nobody knows how to log in.
    Notification::assertSentTo(
        $artist,
        InboundEmailUnreadableNotification::class,
        function (InboundEmailUnreadableNotification $notification) {
            return $notification->isNewAccount === true
                && filled($notification->tempPassword);
        }
    );
});

test('an existing artist is told without being sent a password', function () {
    $artist = User::factory()->create(['email' => 'known@example.com']);

    app(ArtistOnboardingService::class)->onboard(
        'known@example.com',
        $artist->name,
        [unreadableAttachment()],
        'email'
    );

    Notification::assertSentTo(
        $artist,
        InboundEmailUnreadableNotification::class,
        function (InboundEmailUnreadableNotification $notification) {
            return $notification->isNewAccount === false
                && $notification->tempPassword === null;
        }
    );
});

test('the count reported is what they sent, not what survived', function () {
    app(ArtistOnboardingService::class)->onboard(
        'counted@example.com',
        'Counted Artist',
        [unreadableAttachment(), unreadableAttachment(), unreadableAttachment()],
        'email'
    );

    $artist = User::where('email', 'counted@example.com')->firstOrFail();

    Notification::assertSentTo(
        $artist,
        InboundEmailUnreadableNotification::class,
        fn (InboundEmailUnreadableNotification $notification) => $notification->attemptedCount === 3
    );
});

test('a readable batch still sends the receipt and not the failure mail', function () {
    app(ArtistOnboardingService::class)->onboard(
        'works@example.com',
        'Working Artist',
        [readableAttachment()],
        'email'
    );

    $artist = User::where('email', 'works@example.com')->firstOrFail();

    Notification::assertSentTo($artist, InboundEmailReceiptNotification::class);
    Notification::assertNotSentTo($artist, InboundEmailUnreadableNotification::class);
});

test('one readable file among bad ones counts as a success', function () {
    app(ArtistOnboardingService::class)->onboard(
        'mixed@example.com',
        'Mixed Artist',
        [
            unreadableAttachment(),
            readableAttachment(),
            unreadableAttachment(),
        ],
        'email'
    );

    $artist = User::where('email', 'mixed@example.com')->firstOrFail();

    Notification::assertSentTo($artist, InboundEmailReceiptNotification::class);
    Notification::assertNotSentTo($artist, InboundEmailUnreadableNotification::class);
});
