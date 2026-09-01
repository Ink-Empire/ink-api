<?php

namespace Tests\Unit;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Notifications\CalendarDisconnectedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\NotificationLog\Models\NotificationLogItem;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class BackfillCalendarReauthNoticesTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a user fires UserObserver::created, which dispatches a Slack
        // job that the queue would otherwise run inline.
        Queue::fake();
        Notification::fake();
    }

    public function test_it_emails_an_artist_flagged_before_the_notice_existed(): void
    {
        $connection = $this->connection(['requires_reauth' => true]);

        $this->artisan('calendar:backfill-reauth-notices')
            ->expectsConfirmation('Send a disconnection email to 1 artist(s)?', 'yes')
            ->assertExitCode(0);

        Notification::assertSentTo($connection->user, CalendarDisconnectedNotification::class);
    }

    public function test_it_leaves_healthy_connections_alone(): void
    {
        $this->connection(['requires_reauth' => false]);

        $this->artisan('calendar:backfill-reauth-notices')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    /**
     * The whole point of reading the notification log. Running this twice must
     * not email the same artist again.
     */
    public function test_it_skips_an_artist_who_already_had_the_notice(): void
    {
        $connection = $this->connection(['requires_reauth' => true]);

        NotificationLogItem::create([
            'notification_type' => CalendarDisconnectedNotification::class,
            'notifiable_id' => $connection->user_id,
            'notifiable_type' => User::class,
            'channel' => 'mail',
        ]);

        $this->artisan('calendar:backfill-reauth-notices')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_dry_run_sends_nothing(): void
    {
        $this->connection(['requires_reauth' => true]);

        $this->artisan('calendar:backfill-reauth-notices', ['--dry-run' => true])
            ->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_declining_the_confirmation_sends_nothing(): void
    {
        $this->connection(['requires_reauth' => true]);

        $this->artisan('calendar:backfill-reauth-notices')
            ->expectsConfirmation('Send a disconnection email to 1 artist(s)?', 'no')
            ->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_count_limits_how_many_are_processed(): void
    {
        $this->connection(['requires_reauth' => true]);
        $this->connection(['requires_reauth' => true]);
        $this->connection(['requires_reauth' => true]);

        $this->artisan('calendar:backfill-reauth-notices', ['--count' => 2])
            ->expectsConfirmation('Send a disconnection email to 2 artist(s)?', 'yes')
            ->assertExitCode(0);

        Notification::assertCount(2);
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
