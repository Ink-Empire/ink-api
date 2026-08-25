<?php

/**
 * Production health checks and the alerting around them.
 *
 * The alerting matters as much as the checks. An hourly job that reposts every
 * failing check until it is fixed trains everyone to mute the channel, so the
 * state machine is tested directly.
 */

use App\Enums\HealthStatus;
use App\Models\Studio;
use App\Services\HealthCheckService;
use App\Services\SlackService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config()->set('health.alerts.enabled', true);
});

it('summarises to the worst status among its checks', function () {
    expect(HealthStatus::worst([HealthStatus::OK, HealthStatus::OK]))->toBe(HealthStatus::OK)
        ->and(HealthStatus::worst([HealthStatus::OK, HealthStatus::WARN]))->toBe(HealthStatus::WARN)
        ->and(HealthStatus::worst([HealthStatus::WARN, HealthStatus::CRITICAL]))->toBe(HealthStatus::CRITICAL);
});

it('reports every check with a name, status and message', function () {
    $result = app(HealthCheckService::class)->deep();

    expect($result)->toHaveKeys(['status', 'checked_at', 'checks'])
        ->and($result['checks'])->not->toBeEmpty();

    foreach ($result['checks'] as $check) {
        expect($check)->toHaveKeys(['name', 'status', 'message', 'detail'])
            ->and($check['message'])->not->toBeEmpty();
    }
});

it('does not run elasticsearch checks when elasticsearch is unreachable', function () {
    // Every index check would fail for the same reason, turning one real
    // problem into a wall of misleading alerts.
    $result = app(HealthCheckService::class)->deep();

    $names = array_column($result['checks'], 'name');
    $elastic = collect($result['checks'])->firstWhere('name', 'elasticsearch');

    if ($elastic['status'] === HealthStatus::OK) {
        expect($names)->toContain('index_drift');
    } else {
        expect($names)->not->toContain('index_drift')
            ->and($names)->toContain('queue_depth');
    }
});

it('still runs the database checks when elasticsearch is down', function () {
    $names = array_column(app(HealthCheckService::class)->deep()['checks'], 'name');

    expect($names)->toContain('database')
        ->and($names)->toContain('queue_depth')
        ->and($names)->toContain('claimed_studios_have_owners');
});

it('flags a claimed studio that has no owner', function () {
    Studio::factory()->create(['is_claimed' => true, 'owner_id' => null]);

    $checks = collect(app(HealthCheckService::class)->deep()['checks']);
    $check = $checks->firstWhere('name', 'claimed_studios_have_owners');

    expect($check['status'])->toBe(HealthStatus::WARN)
        ->and($check['detail']['count'])->toBeGreaterThan(0);
});

it('ignores demo studios, which are ownerless by design', function () {
    Studio::factory()->count(3)->create([
        'is_claimed' => true,
        'owner_id' => null,
        'is_demo' => true,
    ]);

    $checks = collect(app(HealthCheckService::class)->deep()['checks']);
    $check = $checks->firstWhere('name', 'claimed_studios_have_owners');

    expect($check['status'])->toBe(HealthStatus::OK);
});

/**
 * Other checks fail alongside this one when Elasticsearch is unavailable, so
 * these assert on messages for the check under test rather than a total count.
 */
function opsMessages(array &$sent): void
{
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldReceive('notifyOps')->andReturnUsing(function ($title, $body) use (&$sent) {
        $sent[] = $title . ' ' . $body;

        return true;
    });

    app()->instance(SlackService::class, $slack);
}

it('alerts once when a check starts failing and stays quiet while it keeps failing', function () {
    // Exercises the transition machinery, so it lowers the bar to warnings.
    // The severity gate itself is covered separately.
    config()->set('health.alerts.minimum_severity', HealthStatus::WARN);

    $sent = [];
    opsMessages($sent);

    Studio::factory()->create(['is_claimed' => true, 'owner_id' => null]);

    $this->artisan('ops:health-check');
    $this->artisan('ops:health-check');
    $this->artisan('ops:health-check');

    $forCheck = array_values(array_filter(
        $sent,
        fn ($message) => str_contains($message, 'claimed_studios_have_owners')
    ));

    expect($forCheck)->toHaveCount(1)
        ->and($forCheck[0])->toContain('Health check failing');
});

it('posts a recovery message when a failing check comes back', function () {
    config()->set('health.alerts.minimum_severity', HealthStatus::WARN);

    $sent = [];
    opsMessages($sent);

    $studio = Studio::factory()->create(['is_claimed' => true, 'owner_id' => null]);

    $this->artisan('ops:health-check');

    $studio->update(['is_claimed' => false]);

    $this->artisan('ops:health-check');

    $forCheck = array_values(array_filter(
        $sent,
        fn ($message) => str_contains($message, 'claimed_studios_have_owners')
    ));

    expect($forCheck)->toHaveCount(2)
        ->and($forCheck[0])->toContain('Health check failing')
        ->and($forCheck[1])->toContain('Health check recovered');
});

it('does not ping the channel for a warning', function () {
    // A studio with no owner is a warning. Worth seeing in the weekly report,
    // not worth waking anyone for.
    $sent = [];
    opsMessages($sent);

    Studio::factory()->create(['is_claimed' => true, 'owner_id' => null]);

    $this->artisan('ops:health-check');

    $forCheck = array_filter(
        $sent,
        fn ($message) => str_contains($message, 'claimed_studios_have_owners')
    );

    expect($forCheck)->toBeEmpty();
});

it('still pings the channel for a critical', function () {
    $sent = [];
    opsMessages($sent);

    // Elasticsearch is unavailable in tests, so its check is critical.
    $this->artisan('ops:health-check');

    $critical = array_filter($sent, fn ($message) => str_contains($message, 'CRITICAL'));

    expect($critical)->not->toBeEmpty();
});

it('reports every check in the weekly summary, warnings included', function () {
    $sent = [];
    opsMessages($sent);

    Studio::factory()->create(['is_claimed' => true, 'owner_id' => null]);

    $this->artisan('ops:health-check --summary');

    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toContain('Weekly health report')
        ->and($sent[0])->toContain('claimed_studios_have_owners')
        ->and($sent[0])->toContain('passing');
});

it('sends nothing to slack when alerting is disabled', function () {
    config()->set('health.alerts.enabled', false);

    $slack = Mockery::mock(SlackService::class);
    $slack->shouldNotReceive('notifyOps');
    app()->instance(SlackService::class, $slack);

    Studio::factory()->create(['is_claimed' => true, 'owner_id' => null]);

    $this->artisan('ops:health-check');
});

it('keeps counts and ids out of the public endpoint', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJsonStructure(['status', 'checked_at']);

    expect($response->json())->not->toHaveKey('checks');
});
