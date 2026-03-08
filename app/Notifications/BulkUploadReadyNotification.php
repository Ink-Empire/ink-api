<?php

namespace App\Notifications;

use App\Models\BulkUpload;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use App\Notifications\Traits\RespectsPushPreferences;

class BulkUploadReadyNotification extends Notification
{
    use RespectsPushPreferences;

    public const EVENT_TYPE = 'bulk_upload_ready';

    public function __construct(
        public BulkUpload $bulkUpload,
        public int $photoCount
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->filterChannelsForPush($notifiable, [FcmChannel::class]);
        $channels[] = 'database';

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => self::EVENT_TYPE,
            'message' => "Your {$this->photoCount} photos are ready to review",
            'entity_type' => 'bulk_upload',
            'entity_id' => $this->bulkUpload->id,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: 'Photos ready to review',
            body: "Your {$this->photoCount} photos are ready — add details and publish",
        )))
            ->data([
                'type' => self::EVENT_TYPE,
                'bulk_upload_id' => (string) $this->bulkUpload->id,
            ])
            ->custom([
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                        ],
                    ],
                ],
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'bulk_upload_id' => $this->bulkUpload->id,
            'photo_count' => $this->photoCount,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'reference_id' => $this->bulkUpload->id,
            'reference_type' => BulkUpload::class,
        ];
    }
}
