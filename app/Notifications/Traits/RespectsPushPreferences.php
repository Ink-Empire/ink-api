<?php

namespace App\Notifications\Traits;

use NotificationChannels\Fcm\FcmChannel;

trait RespectsPushPreferences
{
    /**
     * Filter out the FCM channel if the user has disabled push for this notification type
     * or has no device tokens registered.
     */
    protected function filterChannelsForPush(object $notifiable, array $channels): array
    {
        if (!in_array(FcmChannel::class, $channels)) {
            return $channels;
        }

        $eventType = defined('static::EVENT_TYPE') ? static::EVENT_TYPE : null;

        // Remove FCM if user has no device tokens
        if (!method_exists($notifiable, 'routeNotificationForFcm')
            || empty($notifiable->routeNotificationForFcm())) {
            return array_values(array_filter($channels, fn ($ch) => $ch !== FcmChannel::class));
        }

        // Remove FCM if user has explicitly disabled push for this type
        if ($eventType
            && method_exists($notifiable, 'wantsPushNotification')
            && !$notifiable->wantsPushNotification($eventType)) {
            return array_values(array_filter($channels, fn ($ch) => $ch !== FcmChannel::class));
        }

        return $channels;
    }
}
