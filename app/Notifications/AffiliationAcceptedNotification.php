<?php

namespace App\Notifications;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use App\Notifications\Traits\RespectsEmailPreferences;

class AffiliationAcceptedNotification extends Notification
{
    use RespectsEmailPreferences;

    public const EVENT_TYPE = 'affiliation_accepted';

    /**
     * @param User $acceptedBy The user who accepted the invitation/request
     * @param Studio $studio The studio involved
     * @param string $acceptedByType 'artist' if artist accepted studio's invite, 'studio' if studio approved artist's request
     */
    public function __construct(
        public User $acceptedBy,
        public Studio $studio,
        public string $acceptedByType
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        $accepterName = $this->acceptedBy->name ?? 'Someone';
        $studioName = $this->studio->name ?? 'the studio';

        if ($this->acceptedByType === 'artist') {
            // Artist accepted studio's invitation - notify studio owner
            $subject = "{$accepterName} has joined {$studioName}!";
            $dashboardUrl = $frontendUrl . '/dashboard/studio/artists';
            $message = "{$accepterName} has accepted your invitation to join {$studioName}. They are now a verified member of your studio.";
        } else {
            // Studio approved artist's request - notify artist
            $subject = "Your request to join {$studioName} was approved!";
            $dashboardUrl = $frontendUrl . '/dashboard';
            $message = "Great news! {$studioName} has approved your request to join. You are now a verified member of the studio.";
        }

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("{$subject} - InkedIn")
            ->view('mail.affiliation-accepted', [
                'accepterName' => $accepterName,
                'studioName' => $studioName,
                'bodyMessage' => $message,
                'dashboardUrl' => $dashboardUrl,
                'acceptedByType' => $this->acceptedByType,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'accepted_by_id' => $this->acceptedBy->id,
            'accepted_by_name' => $this->acceptedBy->name,
            'studio_id' => $this->studio->id,
            'accepted_by_type' => $this->acceptedByType,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->acceptedBy->id,
            'sender_type' => User::class,
            'reference_id' => $this->studio->id,
            'reference_type' => Studio::class,
        ];
    }
}
