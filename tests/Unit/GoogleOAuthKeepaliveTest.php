<?php

namespace Tests\Unit;

use App\Exceptions\CalendarReauthRequiredException;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\SlackService;
use Illuminate\Support\Facades\Queue;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class GoogleOAuthKeepaliveTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshTestDatabase;

    private MockInterface $google;

    private MockInterface $slack;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a user fires UserObserver::created, which dispatches
        // SendSlackNewUserNotification. The queue runs sync under phpunit, so
        // without this it reaches the SlackService mock as an unexpected
        // notifyNewUser call and fails every test that builds a connection.
        Queue::fake();

        config(['services.google.client_id' => '123456789-abc.apps.googleusercontent.com']);
        config(['services.google.keepalive_connection_id' => null]);

        // Bound before the command is resolved so both are injected.
        $this->google = $this->mock(GoogleCalendarService::class);
        $this->slack = $this->mock(SlackService::class);
    }

    public function test_it_refreshes_the_designated_connection(): void
    {
        $this->connection();
        $designated = $this->connection();

        config(['services.google.keepalive_connection_id' => $designated->id]);

        $this->expectRefreshOf($designated->id);
        $this->slack->shouldNotReceive('notifyOps');

        $this->artisan('google:keepalive')->assertExitCode(0);
    }

    public function test_it_falls_back_to_the_most_recent_usable_connection(): void
    {
        $this->connection(['created_at' => now()->subMonth()]);
        $newest = $this->connection(['created_at' => now()]);

        $this->expectRefreshOf($newest->id);
        $this->slack->shouldNotReceive('notifyOps');

        $this->artisan('google:keepalive')->assertExitCode(0);
    }

    public function test_it_skips_connections_that_need_reauth(): void
    {
        $this->connection(['created_at' => now(), 'requires_reauth' => true]);
        $usable = $this->connection(['created_at' => now()->subMonth()]);

        $this->expectRefreshOf($usable->id);
        $this->slack->shouldNotReceive('notifyOps');

        $this->artisan('google:keepalive')->assertExitCode(0);
    }

    public function test_it_alerts_ops_when_there_is_no_usable_connection(): void
    {
        $this->connection(['requires_reauth' => true]);

        $this->google->shouldNotReceive('refreshToken');
        $this->expectOpsAlert();

        $this->artisan('google:keepalive')->assertExitCode(1);
    }

    public function test_it_alerts_ops_when_the_connection_can_no_longer_refresh(): void
    {
        $this->connection();

        $this->google
            ->shouldReceive('refreshToken')
            ->once()
            ->andThrow(new CalendarReauthRequiredException('needs reauth'));

        $this->expectOpsAlert();

        $this->artisan('google:keepalive')->assertExitCode(1);
    }

    public function test_it_alerts_ops_when_the_refresh_throws(): void
    {
        $this->connection();

        $this->google
            ->shouldReceive('refreshToken')
            ->once()
            ->andThrow(new \RuntimeException('google is down'));

        $this->expectOpsAlert();

        $this->artisan('google:keepalive')->assertExitCode(1);
    }

    public function test_it_alerts_ops_when_no_client_id_is_configured(): void
    {
        config(['services.google.client_id' => '']);

        $this->google->shouldNotReceive('refreshToken');
        $this->expectOpsAlert();

        $this->artisan('google:keepalive')->assertExitCode(1);
    }

    private function connection(array $overrides = []): CalendarConnection
    {
        // created_at is not fillable, so mass assignment drops it and Eloquent
        // stamps every row with now(). Left that way the ordering tests build
        // rows that are all the same age and assert nothing.
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $connection = CalendarConnection::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'provider' => 'google',
            'provider_account_id' => (string) fake()->randomNumber(8),
            'provider_email' => fake()->unique()->safeEmail(),
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'requires_reauth' => false,
        ], $overrides));

        if ($createdAt) {
            $connection->forceFill(['created_at' => $createdAt])->save();
        }

        return $connection;
    }

    private function expectRefreshOf(int $connectionId): void
    {
        $this->google
            ->shouldReceive('refreshToken')
            ->once()
            ->withArgs(fn (CalendarConnection $connection) => $connection->id === $connectionId);
    }

    private function expectOpsAlert(): void
    {
        $this->slack
            ->shouldReceive('notifyOps')
            ->once()
            ->with('Google OAuth keepalive failed', \Mockery::type('string'));
    }
}
