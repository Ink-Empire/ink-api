<?php

/**
 * An unreachable setup mailbox is silent by nature: artists email their work
 * in, hear nothing, and the only trace is a log line. It ran broken for 26
 * days before anyone noticed.
 *
 * Alerting on the first failed run would post every three minutes, which
 * people learn to ignore, so the command waits for the failure to persist and
 * then speaks once. These pin both halves of that.
 */

use App\Services\SlackService;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

const OUTAGE_SINCE_KEY = 'inbound_email:unreachable_since';
const OUTAGE_ALERTED_KEY = 'inbound_email:outage_alerted';

beforeEach(function () {
    Cache::forget(OUTAGE_SINCE_KEY);
    Cache::forget(OUTAGE_ALERTED_KEY);

    // No mailbox is configured in the test environment, so the command exits
    // before reaching IMAP. These drive the outage reporting directly, which
    // is the behaviour under test.
    $this->command = app(\App\Console\Commands\FetchInboundEmails::class);
});

function reportOutage(string $error = 'Connection refused'): void
{
    $method = new ReflectionMethod(\App\Console\Commands\FetchInboundEmails::class, 'reportOutage');
    $method->invoke(test()->command, $error);
}

function reportRecovery(): void
{
    $method = new ReflectionMethod(\App\Console\Commands\FetchInboundEmails::class, 'reportRecovery');
    $method->invoke(test()->command);
}

it('says nothing on the first failure', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldNotReceive('notifyOps');
    app()->instance(SlackService::class, $slack);

    reportOutage();

    expect(Cache::get(OUTAGE_SINCE_KEY))->not->toBeNull()
        ->and(Cache::get(OUTAGE_ALERTED_KEY))->toBeNull();
});

it('stays quiet while the outage is still short', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldNotReceive('notifyOps');
    app()->instance(SlackService::class, $slack);

    Cache::put(OUTAGE_SINCE_KEY, now()->subMinutes(5)->toIso8601String(), now()->addDay());

    reportOutage();

    expect(Cache::get(OUTAGE_ALERTED_KEY))->toBeNull();
});

it('speaks once the outage has lasted long enough', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldReceive('notifyOps')->once()->andReturn(true);
    app()->instance(SlackService::class, $slack);

    Cache::put(OUTAGE_SINCE_KEY, now()->subHour()->toIso8601String(), now()->addDay());

    reportOutage();

    expect(Cache::get(OUTAGE_ALERTED_KEY))->toBeTrue();
});

/**
 * The command runs every three minutes. Without this it would post twenty
 * times an hour for as long as the mailbox stayed down.
 */
it('does not repeat the alert on later failures', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldNotReceive('notifyOps');
    app()->instance(SlackService::class, $slack);

    Cache::put(OUTAGE_SINCE_KEY, now()->subHour()->toIso8601String(), now()->addDay());
    Cache::put(OUTAGE_ALERTED_KEY, true, now()->addDays(7));

    reportOutage();
});

it('reports the recovery when an alert went out', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldReceive('notifyOps')->once()->andReturn(true);
    app()->instance(SlackService::class, $slack);

    Cache::put(OUTAGE_SINCE_KEY, now()->subHour()->toIso8601String(), now()->addDay());
    Cache::put(OUTAGE_ALERTED_KEY, true, now()->addDays(7));

    reportRecovery();

    expect(Cache::get(OUTAGE_SINCE_KEY))->toBeNull()
        ->and(Cache::get(OUTAGE_ALERTED_KEY))->toBeNull();
});

/**
 * A blip that resolved before anyone was told does not need an all-clear.
 */
it('says nothing about recovering from an outage nobody heard about', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldNotReceive('notifyOps');
    app()->instance(SlackService::class, $slack);

    Cache::put(OUTAGE_SINCE_KEY, now()->subMinutes(5)->toIso8601String(), now()->addDay());

    reportRecovery();

    expect(Cache::get(OUTAGE_SINCE_KEY))->toBeNull();
});

it('stays silent when nothing was wrong', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldNotReceive('notifyOps');
    app()->instance(SlackService::class, $slack);

    reportRecovery();
});

/**
 * The alert is a courtesy. A webhook problem must not turn an unreachable
 * mailbox into a second failure on top of it.
 */
it('swallows a failure to send the alert', function () {
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldReceive('notifyOps')->andThrow(new RuntimeException('webhook down'));
    app()->instance(SlackService::class, $slack);

    Cache::put(OUTAGE_SINCE_KEY, now()->subHour()->toIso8601String(), now()->addDay());

    reportOutage();
})->throwsNoExceptions();
