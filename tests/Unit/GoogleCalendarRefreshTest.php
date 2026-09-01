<?php

namespace Tests\Unit;

use App\Enums\GoogleOAuthError;
use App\Exceptions\CalendarReauthRequiredException;
use App\Exceptions\CalendarRefreshFailedException;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Notifications\CalendarDisconnectedNotification;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class GoogleCalendarRefreshTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a user fires UserObserver::created, which dispatches a Slack
        // job that the queue would otherwise run inline.
        Queue::fake();
        Notification::fake();
    }

    public function test_it_marks_the_connection_for_reauth_on_invalid_grant(): void
    {
        $connection = $this->connection();

        $service = $this->serviceReturning(['error' => GoogleOAuthError::INVALID_GRANT]);

        $this->expectException(CalendarReauthRequiredException::class);

        try {
            $service->refreshToken($connection);
        } finally {
            $connection->refresh();
            $this->assertTrue($connection->requires_reauth);
            $this->assertFalse($connection->sync_enabled);
        }
    }

    public function test_it_emails_the_artist_when_the_connection_dies(): void
    {
        $connection = $this->connection();

        $service = $this->serviceReturning(['error' => GoogleOAuthError::INVALID_GRANT]);

        try {
            $service->refreshToken($connection);
        } catch (CalendarReauthRequiredException $e) {
            // Asserted below.
        }

        Notification::assertSentTo(
            $connection->user,
            CalendarDisconnectedNotification::class
        );
    }

    public function test_it_does_not_email_twice_for_a_connection_already_flagged(): void
    {
        $connection = $this->connection(['requires_reauth' => true]);

        $service = $this->serviceReturning(['error' => GoogleOAuthError::INVALID_GRANT]);

        try {
            $service->refreshToken($connection);
        } catch (CalendarReauthRequiredException $e) {
            // Asserted below.
        }

        Notification::assertNothingSent();
    }

    /**
     * The reason this exists. A Google outage must not disconnect an artist who
     * has done nothing wrong.
     */
    public function test_it_leaves_the_connection_alone_on_a_transient_failure(): void
    {
        $connection = $this->connection();

        $service = $this->serviceReturning(['error' => GoogleOAuthError::INVALID_CLIENT]);

        $this->expectException(CalendarRefreshFailedException::class);

        try {
            $service->refreshToken($connection);
        } finally {
            $connection->refresh();
            $this->assertFalse($connection->requires_reauth);
            $this->assertTrue($connection->sync_enabled);
            Notification::assertNothingSent();
        }
    }

    public function test_it_leaves_the_connection_alone_when_google_returns_nothing(): void
    {
        $connection = $this->connection();

        $service = $this->serviceReturning([]);

        $this->expectException(CalendarRefreshFailedException::class);

        try {
            $service->refreshToken($connection);
        } finally {
            $connection->refresh();
            $this->assertFalse($connection->requires_reauth);
            $this->assertTrue($connection->sync_enabled);
        }
    }

    public function test_it_stores_the_new_token_on_success(): void
    {
        $connection = $this->connection();

        $service = $this->serviceReturning([
            'access_token' => 'fresh-access-token',
            'expires_in' => 3600,
        ]);

        $service->refreshToken($connection);

        $connection->refresh();

        $this->assertSame('fresh-access-token', $connection->access_token);
        $this->assertFalse($connection->requires_reauth);
        $this->assertTrue($connection->sync_enabled);
        Notification::assertNothingSent();
    }

    public function test_it_keeps_the_existing_refresh_token_when_google_omits_one(): void
    {
        $connection = $this->connection();

        $service = $this->serviceReturning([
            'access_token' => 'fresh-access-token',
            'expires_in' => 3600,
        ]);

        $service->refreshToken($connection);

        $connection->refresh();

        $this->assertSame('refresh-token', $connection->refresh_token);
    }

    /**
     * The reconnect page is /calendar in the Next.js app, which is also where
     * CalendarOAuthController sends people after a successful connect. An email
     * pointing anywhere else is a dead link the artist cannot recover from.
     */
    public function test_the_reconnect_link_points_at_the_calendar_page(): void
    {
        $connection = $this->connection();

        $mail = (new CalendarDisconnectedNotification($connection))->toMail($connection->user);

        $this->assertStringEndsWith('/calendar', $mail->viewData['reconnectUrl']);
    }

    /**
     * The service builds its own Google client, so the mock is swapped in
     * rather than changing the constructor signature for the sake of a test.
     */
    private function serviceReturning(array $response): GoogleCalendarService
    {
        $client = Mockery::mock(GoogleClient::class);
        $client->shouldReceive('refreshToken')->once()->andReturn($response);

        $service = new GoogleCalendarService;

        $property = new ReflectionProperty($service, 'client');
        $property->setAccessible(true);
        $property->setValue($service, $client);

        return $service;
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
            'sync_enabled' => true,
            'requires_reauth' => false,
        ], $overrides));
    }
}
