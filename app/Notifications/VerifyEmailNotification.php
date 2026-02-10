<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        // Build frontend URL for verification
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $email = urlencode($notifiable->getEmailForVerification());
        $url = $frontendUrl . '/verify-email?url=' . urlencode($verificationUrl) . '&email=' . $email;

        return (new MailMessage)
            ->subject('Verify Your Email Address - InkedIn')
            ->view('mail.verify-email', ['url' => $url]);
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl(object $notifiable): string
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // Ensure HTTPS in production
        if (config('app.env') === 'production') {
            $url = str_replace('http://', 'https://', $url);
        }

        return $url;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
