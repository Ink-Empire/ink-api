<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RefreshCalendarWebhook;
use App\Jobs\RefreshCalendarWebhooks;
use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class RefreshCalendarWebhooksTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_queues_a_job_for_each_expiring_webhook(): void
    {
        $this->connection(['webhook_expires_at' => now()->addHours(2)]);
        $this->connection(['webhook_expires_at' => now()->addHours(6)]);

        (new RefreshCalendarWebhooks)->handle();

        Queue::assertPushed(RefreshCalendarWebhook::class, 2);
    }

    public function test_it_ignores_webhooks_that_are_not_due(): void
    {
        $this->connection(['webhook_expires_at' => now()->addDays(5)]);

        (new RefreshCalendarWebhooks)->handle();

        Queue::assertNotPushed(RefreshCalendarWebhook::class);
    }

    public function test_it_ignores_connections_with_sync_disabled(): void
    {
        $this->connection([
            'webhook_expires_at' => now()->addHours(2),
            'sync_enabled' => false,
        ]);

        (new RefreshCalendarWebhooks)->handle();

        Queue::assertNotPushed(RefreshCalendarWebhook::class);
    }

    public function test_it_ignores_connections_with_no_webhook(): void
    {
        $this->connection(['webhook_expires_at' => null]);

        (new RefreshCalendarWebhooks)->handle();

        Queue::assertNotPushed(RefreshCalendarWebhook::class);
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
