<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Spatie\NotificationLog\Models\NotificationLogItem;

class NotificationStatsService
{
    /**
     * Count notifications sent for a specific event type by a sender.
     */
    public function countBySender(string $eventType, int $senderId): int
    {
        return NotificationLogItem::query()
            ->whereJsonContains('extra->event_type', $eventType)
            ->whereJsonContains('extra->sender_id', $senderId)
            ->count();
    }

    /**
     * Count unique recipients for a specific event type by a sender.
     */
    public function countUniqueRecipientsBySender(string $eventType, int $senderId): int
    {
        return NotificationLogItem::query()
            ->whereJsonContains('extra->event_type', $eventType)
            ->whereJsonContains('extra->sender_id', $senderId)
            ->distinct('notifiable_id')
            ->count('notifiable_id');
    }

    /**
     * Count notifications for a specific event type and reference (e.g., tattoo for beacon).
     */
    public function countByReference(string $eventType, Model $reference): int
    {
        return NotificationLogItem::query()
            ->whereJsonContains('extra->event_type', $eventType)
            ->whereJsonContains('extra->reference_id', $reference->getKey())
            ->whereJsonContains('extra->reference_type', get_class($reference))
            ->count();
    }

    /**
     * Get stats for books_open notifications sent by an artist.
     */
    public function getBooksOpenStats(int $artistId): array
    {
        $query = NotificationLogItem::query()
            ->whereJsonContains('extra->event_type', 'books_open')
            ->whereJsonContains('extra->sender_id', $artistId);

        return [
            'total_sent' => $query->count(),
            'unique_recipients' => (clone $query)->distinct('notifiable_id')->count('notifiable_id'),
            'last_sent_at' => $query->latest()->value('created_at'),
        ];
    }

    /**
     * Get stats for beacon notifications for a specific tattoo.
     */
    public function getBeaconStats(int $tattooId): array
    {
        $query = NotificationLogItem::query()
            ->whereJsonContains('extra->event_type', 'beacon_request')
            ->whereJsonContains('extra->reference_id', $tattooId);

        return [
            'total_sent' => $query->count(),
            'unique_recipients' => (clone $query)->distinct('notifiable_id')->count('notifiable_id'),
            'last_sent_at' => $query->latest()->value('created_at'),
        ];
    }

    /**
     * Get all notification stats for an artist (across all event types).
     */
    public function getArtistStats(int $artistId): array
    {
        return [
            'books_open' => $this->getBooksOpenStats($artistId),
            // Add more event types as needed
        ];
    }
}
