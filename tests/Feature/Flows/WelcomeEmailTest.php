<?php

/**
 * The welcome email used to decide everything from one is-artist boolean, so a
 * studio owner fell into the client branch: they were told new artists join
 * every week and handed a button to the public tattoo feed. Two owners on
 * production received that before it was caught.
 *
 * Each account type gets its own copy and its own destination here, and an
 * unrecognised type throws rather than borrowing the nearest email.
 */

use App\Enums\UserTypes;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();
});

function welcomeFor(int $typeId): array
{
    $user = User::factory()->create(['type_id' => $typeId]);
    $mail = (new WelcomeNotification)->toMail($user);

    return [$mail, (string) $mail->render()];
}

function welcomeFrontendUrl(): string
{
    return config('app.frontend_url', 'http://localhost:4000');
}

test('a client is pointed at the tattoo feed', function () {
    [$mail, $html] = welcomeFor(UserTypes::CLIENT_TYPE_ID);

    expect($mail->subject)->toBe("What's next: finding your tattoo");
    expect($html)
        ->toContain('Welcome to the new way to find your next tattoo.')
        ->toContain('Start Exploring')
        ->toContain(welcomeFrontendUrl().'/tattoos');
});

test('a client does not get the artist or studio copy', function () {
    [, $html] = welcomeFor(UserTypes::CLIENT_TYPE_ID);

    expect($html)
        ->toContain("Here's the deal")
        ->not->toContain('Complete Your Profile')
        ->not->toContain('Set up your studio page')
        ->not->toContain('Four things worth doing now');
});

test('an artist is pointed at the dashboard', function () {
    [$mail, $html] = welcomeFor(UserTypes::ARTIST_TYPE_ID);

    expect($mail->subject)->toBe("What's next: getting your work seen");
    expect($html)
        ->toContain('Welcome to the new way to showcase your work.')
        ->toContain('Complete Your Profile')
        ->toContain(welcomeFrontendUrl().'/dashboard');
});

test('an artist does not get the client or studio copy', function () {
    [, $html] = welcomeFor(UserTypes::ARTIST_TYPE_ID);

    expect($html)
        ->toContain("Here's the deal")
        ->not->toContain('Start Exploring')
        ->not->toContain('Set up your studio page')
        ->not->toContain('Four things worth doing now');
});

test('a studio owner is pointed at the dashboard', function () {
    [$mail, $html] = welcomeFor(UserTypes::STUDIO_TYPE_ID);

    expect($mail->subject)->toBe("What's next: getting your shop on the map");
    expect($html)
        ->toContain('Set up your studio page')
        ->toContain(welcomeFrontendUrl().'/dashboard');
});

test('the studio copy covers the four things an owner should do', function () {
    [, $html] = welcomeFor(UserTypes::STUDIO_TYPE_ID);

    expect($html)
        ->toContain('Thanks for signing up!')
        ->toContain('A studio page on InkedIn is how people find your shop by location')
        ->toContain('Four things worth doing now')
        ->toContain('Add your address.')
        ->toContain('Pick a layout and fill the page.')
        ->toContain('Bring your artists in.')
        ->toContain("Say if you're after guest artists.")
        ->toContain('Help us grow this community')
        ->toContain("don't hesitate to reach out")
        ->toContain('-Caroline, founder of InkedIn');
});

test('a studio owner is never sent the client copy or the tattoo feed', function () {
    [, $html] = welcomeFor(UserTypes::STUDIO_TYPE_ID);

    expect($html)
        ->not->toContain('New artists are joining every week')
        // The studio version goes straight into its four items.
        ->not->toContain("Here's the deal")
        ->not->toContain('Start Exploring')
        ->not->toContain('Complete Your Profile')
        ->not->toContain(welcomeFrontendUrl().'/tattoos');
});

test('every version opens with the same gold headline', function () {
    foreach ([UserTypes::CLIENT_TYPE_ID, UserTypes::ARTIST_TYPE_ID, UserTypes::STUDIO_TYPE_ID] as $typeId) {
        [, $html] = welcomeFor($typeId);

        expect($html)
            ->toContain("You're signed up.<br>Here's what's next.")
            ->not->toContain("You're in.");
    }
});

test('each version carries its own hidden preheader', function () {
    $expected = [
        UserTypes::CLIENT_TYPE_ID => 'Browse by style, subject and city',
        UserTypes::ARTIST_TYPE_ID => 'Add your work and tag your styles',
        UserTypes::STUDIO_TYPE_ID => 'Add your address, pick a layout',
    ];

    foreach ($expected as $typeId => $line) {
        [, $html] = welcomeFor($typeId);

        expect($html)->toContain($line);

        foreach ($expected as $otherTypeId => $otherLine) {
            if ($otherTypeId !== $typeId) {
                expect($html)->not->toContain($otherLine);
            }
        }
    }
});

test('an account type with no copy fails loudly instead of borrowing another email', function () {
    // Not persisted: type_id is a constrained foreign key, and the audience is
    // resolved before anything touches the database.
    $user = new User(['type_id' => 99]);

    expect(fn () => (new WelcomeNotification)->toMail($user))
        ->toThrow(InvalidArgumentException::class);
});
