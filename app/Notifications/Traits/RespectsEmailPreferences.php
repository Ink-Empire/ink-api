<?php

namespace App\Notifications\Traits;

trait RespectsEmailPreferences
{
    /**
     * Filter out the mail channel if the user has unsubscribed from emails.
     */
    protected function filterChannelsForUnsubscribed(object $notifiable, array $channels): array
    {
        if (method_exists($notifiable, 'wantsMarketingEmails') && !$notifiable->wantsMarketingEmails()) {
            return array_filter($channels, fn($channel) => $channel !== 'mail');
        }

        return $channels;
    }
}
