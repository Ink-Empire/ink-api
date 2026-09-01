<?php

namespace Tests\Unit;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Queue;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class CalendarReconnectTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a user fires UserObserver::created, and the callback queues
        // SyncUserCalendar. Neither is under test here.
        Queue::fake();
    }

    /**
     * A reconnect used to leave requires_reauth set, so the connection held a
     * working token while the sync scheduler and the keepalive both kept
     * filtering it out. The artist saw a connected calendar that never synced.
     */
    public function test_reconnecting_clears_the_reauth_flag(): void
    {
        $user = User::factory()->create();

        $connection = CalendarConnection::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_account_id' => '12345678',
            'provider_email' => 'artist@example.com',
            'access_token' => 'stale-access-token',
            'refresh_token' => 'stale-refresh-token',
            'token_expires_at' => now()->subHour(),
            'sync_enabled' => false,
            'requires_reauth' => true,
        ]);

        $this->mockGoogle();

        $this->get('/api/calendar/callback?code=auth-code&state='.encrypt($user->id))
            ->assertRedirect();

        $connection->refresh();

        $this->assertFalse($connection->requires_reauth);
        $this->assertTrue($connection->sync_enabled);
        $this->assertSame('fresh-access-token', $connection->access_token);
    }

    public function test_a_first_time_connect_is_enabled_and_unflagged(): void
    {
        $user = User::factory()->create();

        $this->mockGoogle();

        $this->get('/api/calendar/callback?code=auth-code&state='.encrypt($user->id))
            ->assertRedirect();

        $connection = CalendarConnection::where('user_id', $user->id)->firstOrFail();

        $this->assertFalse($connection->requires_reauth);
        $this->assertTrue($connection->sync_enabled);
    }

    /**
     * Declining on Google's consent screen is a normal thing for a person to
     * do. It used to return a raw JSON 400, stranding them on an error page
     * with no route back to the app.
     */
    public function test_declining_consent_redirects_back_to_the_calendar(): void
    {
        $this->get('/api/calendar/callback?error=access_denied&state=whatever')
            ->assertRedirectContains('/calendar?calendar_error=')
            ->assertRedirectContains('cancelled');
    }

    public function test_a_missing_code_redirects_instead_of_returning_json(): void
    {
        $this->get('/api/calendar/callback?state=whatever')
            ->assertRedirectContains('/calendar?calendar_error=');
    }

    public function test_an_unreadable_state_redirects_instead_of_returning_json(): void
    {
        $this->get('/api/calendar/callback?code=auth-code&state=not-encrypted')
            ->assertRedirectContains('/calendar?calendar_error=');
    }

    public function test_it_refuses_a_connection_with_no_refresh_token(): void
    {
        $user = User::factory()->create();

        $this->mock(GoogleCalendarService::class, function ($mock) {
            $mock->shouldReceive('exchangeCode')->andReturn([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
            ]);
        });

        $this->get('/api/calendar/callback?code=auth-code&state='.encrypt($user->id))
            ->assertRedirectContains('refresh+token');

        $this->assertDatabaseCount('calendar_connections', 0);
    }

    private function mockGoogle(): void
    {
        $this->mock(GoogleCalendarService::class, function ($mock) {
            $mock->shouldReceive('exchangeCode')->andReturn([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
            ]);

            $mock->shouldReceive('getUserInfo')->andReturn([
                'id' => '12345678',
                'email' => 'artist@example.com',
                'name' => 'Test Artist',
            ]);

            $mock->shouldReceive('initializeWithConnection')->andReturnSelf();
        });
    }
}
