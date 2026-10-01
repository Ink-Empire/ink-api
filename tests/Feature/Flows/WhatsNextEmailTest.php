<?php

/**
 * The second touch, about a week after signup.
 *
 * The eligibility rules matter more than the copy here. This command runs on a
 * schedule against the whole users table, so the tests below pin the things
 * that would be expensive to get wrong: the cutoff that stops a first run
 * sweeping up every historical account, and the check that nobody is emailed
 * twice.
 */

use App\Console\Commands\SendWhatsNextEmails;
use App\Enums\UserTypes;
use App\Jobs\SendWhatsNextNotification;
use App\Models\User;
use App\Notifications\WhatsNextNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\NotificationLog\Models\NotificationLogItem;

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();

    // Every rule here is relative to the cutoff date, and nobody is eligible
    // until a week after it. Pinning the clock well past it keeps these tests
    // meaningful whenever they run, including before the cutoff has passed.
    $this->travelTo(Carbon::parse(SendWhatsNextEmails::ELIGIBLE_FROM)->addDays(60));
});

afterEach(function () {
    $this->travelBack();
});

function eligibleUser(array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'type_id' => UserTypes::STUDIO_TYPE_ID,
        'email_verified_at' => now()->subDays(20),
        'last_login_at' => now()->subDays(3),
        'email_unsubscribed' => false,
    ], $overrides));

    // created_at is not fillable, so it has to be forced after the fact.
    $user->forceFill(['created_at' => $overrides['created_at'] ?? now()->subDays(10)])->save();

    return $user->fresh();
}

test('an account that signed up a week ago and came back is queued', function () {
    $user = eligibleUser();

    $this->artisan('emails:whats-next')->assertSuccessful();

    Queue::assertPushed(SendWhatsNextNotification::class,
        fn ($job) => $job->userId === $user->id);
});

test('an account that signed up too recently waits', function () {
    eligibleUser(['created_at' => now()->subDays(2)]);

    $this->artisan('emails:whats-next')->assertSuccessful();

    Queue::assertNotPushed(SendWhatsNextNotification::class);
});

test('an account from before the cutoff is never swept up', function () {
    eligibleUser(['created_at' => Carbon::parse(SendWhatsNextEmails::ELIGIBLE_FROM)->subDay()]);

    $this->artisan('emails:whats-next')->assertSuccessful();

    Queue::assertNotPushed(SendWhatsNextNotification::class);
});

test('an account that never came back is skipped', function () {
    eligibleUser(['last_login_at' => null]);

    $this->artisan('emails:whats-next')->assertSuccessful();

    Queue::assertNotPushed(SendWhatsNextNotification::class);
});

test('an unverified account is skipped', function () {
    eligibleUser(['email_verified_at' => null]);

    $this->artisan('emails:whats-next')->assertSuccessful();

    Queue::assertNotPushed(SendWhatsNextNotification::class);
});

test('an unsubscribed account is skipped', function () {
    eligibleUser(['email_unsubscribed' => true]);

    $this->artisan('emails:whats-next')->assertSuccessful();

    Queue::assertNotPushed(SendWhatsNextNotification::class);
});

test('somebody who already had it is not sent it again', function () {
    $user = eligibleUser();

    NotificationLogItem::create([
        'notification_type' => WhatsNextNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'channel' => 'mail',
    ]);

    $this->artisan('emails:whats-next')->assertSuccessful();

    Queue::assertNotPushed(SendWhatsNextNotification::class);
});

test('a dry run reports without queueing anything', function () {
    eligibleUser();

    $this->artisan('emails:whats-next', ['--dry-run' => true])->assertSuccessful();

    Queue::assertNotPushed(SendWhatsNextNotification::class);
});

test('each account type gets its own copy and its own button', function () {
    $expected = [
        UserTypes::CLIENT_TYPE_ID => ['Save the work you keep coming back to', '/tattoos'],
        UserTypes::ARTIST_TYPE_ID => ['Tag your styles and subjects', '/dashboard'],
        UserTypes::STUDIO_TYPE_ID => ['Answer the artists asking to join', '/dashboard'],
    ];

    foreach ($expected as $typeId => [$line, $path]) {
        $user = User::factory()->create(['type_id' => $typeId]);
        $html = (string) (new WhatsNextNotification)->toMail($user)->render();

        expect($html)->toContain($line)
            ->toContain(config('app.frontend_url').$path);

        foreach ($expected as $otherTypeId => [$otherLine, $otherPath]) {
            if ($otherTypeId !== $typeId) {
                expect($html)->not->toContain($otherLine);
            }
        }
    }
});

test('the follow-up leads with its own headline, not the welcome one', function () {
    $html = (string) (new WhatsNextNotification)->toMail(eligibleUser())->render();

    expect($html)
        ->toContain('color: #D4A853;">Here\'s what\'s next.</h1>')
        ->not->toContain("You're signed up.")
        ->not->toContain("You're in.");
});

test('an account type with no copy fails loudly', function () {
    expect(fn () => (new WhatsNextNotification)->toMail(new User(['type_id' => 99])))
        ->toThrow(InvalidArgumentException::class);
});

test('the admin preview can force any version without an account behind it', function () {
    $anonymous = new AnonymousNotifiable;
    $anonymous->route('mail', 'someone@example.com');

    foreach ([UserTypes::CLIENT, UserTypes::ARTIST, UserTypes::STUDIO] as $audience) {
        expect((new WhatsNextNotification($audience))->toMail($anonymous)->subject)
            ->toStartWith("What's next");
    }
});
