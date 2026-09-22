<?php

/**
 * Exactly one run an hour, the one at the top of the hour, has its connection
 * to the mailbox refused. The nineteen either side of it connect fine from the
 * same host with the same credentials, and a message emailed in at :18 was
 * picked up and processed normally while the :00 run that hour had failed.
 *
 * The cause is not known. What is known is that a second attempt moments later
 * succeeds, and that a mailbox which is genuinely down fails both attempts, so
 * retrying cannot hide a real outage. These pin that it retries, and that it
 * still reports a mailbox that is really unreachable.
 */

use App\Console\Commands\FetchInboundEmails;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    Cache::forget('inbound_email:unreachable_since');
    Cache::forget('inbound_email:outage_alerted');

    config([
        'services.inbound_imap.connect_attempts' => 2,
        // Nothing should actually sleep in a test.
        'services.inbound_imap.retry_seconds' => 0,
    ]);
});

/**
 * Drives the private retry loop with a stubbed connection attempt, so no test
 * reaches a real mail server.
 */
function connectWith(array $results): array
{
    $command = new class($results) extends FetchInboundEmails
    {
        public int $attempts = 0;

        public function __construct(private array $results)
        {
            parent::__construct();
        }

        protected function openMailbox(string $mailbox, string $username, string $password)
        {
            $this->attempts++;

            return array_shift($this->results) ?? false;
        }
    };

    $connect = new ReflectionMethod(FetchInboundEmails::class, 'connect');
    $connection = $connect->invoke($command, '{host:993}INBOX', 'user', 'password');

    return [$connection, $command->attempts];
}

it('does not retry when the first attempt works', function () {
    [$connection, $attempts] = connectWith(['a-connection']);

    expect($connection)->toBe('a-connection')
        ->and($attempts)->toBe(1);
});

/**
 * The top-of-hour refusal. One attempt reported it as an outage; two do not.
 */
it('recovers when the first attempt is refused and the second is not', function () {
    [$connection, $attempts] = connectWith([false, 'a-connection']);

    expect($connection)->toBe('a-connection')
        ->and($attempts)->toBe(2);
});

/**
 * A mailbox that is genuinely down fails both attempts, so a real outage is
 * still reported rather than retried into silence.
 */
it('gives up when every attempt is refused', function () {
    [$connection, $attempts] = connectWith([false, false]);

    expect($connection)->toBeFalse()
        ->and($attempts)->toBe(2);
});

it('honours a configured attempt count', function () {
    config(['services.inbound_imap.connect_attempts' => 4]);

    [$connection, $attempts] = connectWith([false, false, false, 'a-connection']);

    expect($connection)->toBe('a-connection')
        ->and($attempts)->toBe(4);
});

/**
 * Retrying has to stay switchable off, or an outage takes twice as long to
 * report as it used to.
 */
it('can be configured not to retry at all', function () {
    config(['services.inbound_imap.connect_attempts' => 1]);

    [$connection, $attempts] = connectWith([false, 'a-connection']);

    expect($connection)->toBeFalse()
        ->and($attempts)->toBe(1);
});
