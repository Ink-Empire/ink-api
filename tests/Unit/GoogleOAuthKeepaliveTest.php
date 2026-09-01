<?php

namespace Tests\Unit;

use App\Exceptions\CalendarReauthRequiredException;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class GoogleOAuthKeepaliveTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => '123456789-abc.apps.googleusercontent.com']);
        config(['services.google.keepalive_connection_id' => null]);
    }

    public function test_it_refreshes_the_designated_connection(): void
    {
        $this->connection();
        $designated = $this->connection();

        config(['services.google.keepalive_connection_id' => $designated->id]);

        $this->expectRefreshOf($designated->id);

        $this->artisan('google:keepalive')->assertExitCode(0);
    }

    public function test_it_falls_back_to_the_most_recent_usable_connection(): void
    {
        $this->connection(['created_at' => now()->subMonth()]);
        $newest = $this->connection(['created_at' => now()]);

        $this->expectRefreshOf($newest->id);

        $this->artisan('google:keepalive')->assertExitCode(0);
    }

    public function test_it_skips_connections_that_need_reauth(): void
    {
        $this->connection(['created_at' => now(), 'requires_reauth' => true]);
        $usable = $this->connection(['created_at' => now()->subMonth()]);

        $this->expectRefreshOf($usable->id);

        $this->artisan('google:keepalive')->assertExitCode(0);
    }

    public function test_it_fails_when_there_is_no_usable_connection(): void
    {
        $this->connection(['requires_reauth' => true]);

        $this->mockService()->shouldNotReceive('refreshToken');

        $this->artisan('google:keepalive')->assertExitCode(1);
    }

    public function test_it_fails_when_the_connection_can_no_longer_refresh(): void
    {
        $this->connection();

        $this->mockService()
            ->shouldReceive('refreshToken')
            ->once()
            ->andThrow(new CalendarReauthRequiredException('needs reauth'));

        $this->artisan('google:keepalive')->assertExitCode(1);
    }

    private function connection(array $overrides = []): CalendarConnection
    {
        return CalendarConnection::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'provider' => 'google',
            'provider_account_id' => (string) fake()->randomNumber(8),
            'provider_email' => fake()->unique()->safeEmail(),
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'requires_reauth' => false,
        ], $overrides));
    }

    private function mockService(): Mockery\MockInterface
    {
        return $this->mock(GoogleCalendarService::class);
    }

    private function expectRefreshOf(int $connectionId): void
    {
        $this->mockService()
            ->shouldReceive('refreshToken')
            ->once()
            ->withArgs(fn (CalendarConnection $connection) => $connection->id === $connectionId);
    }
}
