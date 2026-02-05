<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\BookingRequestNotification;
use App\Notifications\BooksOpenNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Tests\Traits\RefreshTestDatabase;
use Tests\TestCase;

class EmailUnsubscribeTest extends TestCase
{
    use RefreshTestDatabase;

    public function test_user_wants_marketing_emails_returns_true_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->wantsMarketingEmails());
    }

    public function test_user_wants_marketing_emails_returns_false_when_unsubscribed(): void
    {
        $user = User::factory()->create(['email_unsubscribed' => true]);

        $this->assertFalse($user->wantsMarketingEmails());
    }

    public function test_marketing_notification_filters_mail_channel_when_unsubscribed(): void
    {
        $user = User::factory()->create(['email_unsubscribed' => true]);
        $artist = User::factory()->asArtist()->create();

        $notification = new BooksOpenNotification($artist);
        $channels = $notification->via($user);

        $this->assertNotContains('mail', $channels);
    }

    public function test_marketing_notification_includes_mail_channel_when_subscribed(): void
    {
        $user = User::factory()->create(['email_unsubscribed' => false]);
        $artist = User::factory()->asArtist()->create();

        $notification = new BooksOpenNotification($artist);
        $channels = $notification->via($user);

        $this->assertContains('mail', $channels);
    }

    public function test_transactional_reset_password_always_sends_email(): void
    {
        $user = User::factory()->create(['email_unsubscribed' => true]);

        $notification = new ResetPasswordNotification('test-token');
        $channels = $notification->via($user);

        $this->assertContains('mail', $channels);
    }

    public function test_transactional_verify_email_always_sends_email(): void
    {
        $user = User::factory()->create(['email_unsubscribed' => true]);

        $notification = new VerifyEmailNotification();
        $channels = $notification->via($user);

        $this->assertContains('mail', $channels);
    }

    public function test_update_email_preferences_endpoint_updates_user(): void
    {
        $user = User::factory()->create(['email_unsubscribed' => false]);

        $response = $this->actingAs($user)
            ->putJson('/api/users/me/email-preferences', [
                'email_unsubscribed' => true,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'email_unsubscribed' => true,
            ]);

        $this->assertTrue($user->fresh()->email_unsubscribed);
    }

    public function test_update_email_preferences_endpoint_requires_auth(): void
    {
        $response = $this->putJson('/api/users/me/email-preferences', [
            'email_unsubscribed' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_update_email_preferences_requires_boolean(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->putJson('/api/users/me/email-preferences', [
                'email_unsubscribed' => 'invalid',
            ]);

        $response->assertStatus(422);
    }

    public function test_self_user_resource_includes_email_unsubscribed(): void
    {
        $user = User::factory()->create(['email_unsubscribed' => true]);

        $response = $this->actingAs($user)
            ->getJson('/api/users/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.email_unsubscribed', true);
    }
}
