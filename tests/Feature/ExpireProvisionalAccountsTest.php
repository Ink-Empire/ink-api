<?php

/**
 * Provisional accounts are created two ways, and only one of them expires.
 *
 * An account from the setup mailbox is created by a stranger emailing in, so
 * an unclaimed one after fourteen days is abandoned and gets cleaned up. An
 * account built through the admin panel is one a person deliberately made for
 * an artist they are onboarding, and deleting that out from under them would
 * destroy work. The command draws the line on the presence of an
 * InboundEmailLog row, which only the mailbox path writes.
 *
 * These pin that difference so it stays a decision rather than an accident.
 */

use App\Models\InboundEmailLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();
});

function provisionalArtist(string $email, int $daysOld): User
{
    $user = User::factory()->asArtist()->create([
        'email' => $email,
        'force_password_reset' => true,
        'last_login_at' => null,
    ]);

    // created_at is not fillable, so the factory's value has to be forced.
    $user->forceFill(['created_at' => now()->subDays($daysOld)])->save();

    return $user;
}

function inboundLogFor(User $user): void
{
    InboundEmailLog::create([
        'message_uid' => 'uid-'.$user->id,
        'sender_email' => $user->email,
        'sender_name' => $user->name,
        'image_count' => 1,
        'is_processed' => true,
    ]);
}

it('expires an unclaimed account that came from the mailbox', function () {
    $user = provisionalArtist('mailbox@example.com', 20);
    inboundLogFor($user);

    $this->artisan('users:expire-provisional')->assertSuccessful();

    expect(User::find($user->id))->toBeNull();
});

/**
 * The point of this file. An admin built this page on purpose.
 */
it('leaves an account an admin onboarded', function () {
    $user = provisionalArtist('admin-made@example.com', 20);

    $this->artisan('users:expire-provisional')->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
});

it('leaves a mailbox account that is still inside the window', function () {
    $user = provisionalArtist('recent@example.com', 3);
    inboundLogFor($user);

    $this->artisan('users:expire-provisional')->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
});

it('leaves an account whose artist has logged in', function () {
    $user = provisionalArtist('claimed@example.com', 20);
    inboundLogFor($user);
    $user->forceFill(['last_login_at' => now()->subDay()])->save();

    $this->artisan('users:expire-provisional')->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
});

it('deletes nothing on a dry run', function () {
    $user = provisionalArtist('dryrun@example.com', 20);
    inboundLogFor($user);

    $this->artisan('users:expire-provisional', ['--dry-run' => true])->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
});
