<?php

/**
 * The signup address exists to answer "did these two accounts come from one
 * place" when a report lands days or weeks later. Keeping it past that turns
 * a short-lived investigative note into an indefinite record of where every
 * account holder was sitting when they signed up.
 *
 * These pin the ninety day window and the fact that clearing is a sweep, not
 * an edit of the account.
 */

use App\Console\Commands\PruneSignupIps;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();
});

function userSignedUpDaysAgo(int $days, string $email): User
{
    $user = User::factory()->create([
        'email' => $email,
        'signup_ip' => '203.0.113.10',
        'signup_user_agent' => 'TestBrowser/1.0',
    ]);

    // created_at is not fillable, so the factory's value has to be forced.
    $user->forceFill([
        'created_at' => now()->subDays($days),
        'updated_at' => now()->subDays($days),
    ])->save();

    return $user->fresh();
}

it('clears an address recorded past the retention window', function () {
    $user = userSignedUpDaysAgo(PruneSignupIps::RETENTION_DAYS + 1, 'old@example.com');

    $this->artisan('signups:prune-ips')->assertSuccessful();

    expect($user->fresh()->signup_ip)->toBeNull();
});

it('leaves an address recorded inside the retention window', function () {
    $user = userSignedUpDaysAgo(PruneSignupIps::RETENTION_DAYS - 1, 'recent@example.com');

    $this->artisan('signups:prune-ips')->assertSuccessful();

    expect($user->fresh()->signup_ip)->toBe('203.0.113.10');
});

it('changes nothing on a dry run', function () {
    $user = userSignedUpDaysAgo(PruneSignupIps::RETENTION_DAYS + 1, 'old@example.com');

    $this->artisan('signups:prune-ips --dry-run')->assertSuccessful();

    expect($user->fresh()->signup_ip)->toBe('203.0.113.10');
});

/**
 * A sweep is not an account change. Bumping updated_at on every old row would
 * make a retention job look like user activity to anything reading it.
 */
it('does not touch updated_at when it clears an address', function () {
    $user = userSignedUpDaysAgo(PruneSignupIps::RETENTION_DAYS + 1, 'old@example.com');
    $before = $user->updated_at;

    $this->artisan('signups:prune-ips')->assertSuccessful();

    expect($user->fresh()->updated_at->timestamp)->toBe($before->timestamp);
});
