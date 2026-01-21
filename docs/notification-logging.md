# Notification Logging System

InkedIn uses [spatie/laravel-notification-log](https://github.com/spatie/laravel-notification-log) to automatically track all notifications sent through the system. This enables features like showing users how many people received their notifications.

## Overview

When any Laravel notification is sent, the package automatically logs it to the `notification_log_items` table. We extend this with custom `logExtra()` data to track:
- **Event type** - What triggered the notification (e.g., `books_open`, `beacon_request`)
- **Sender** - Who triggered it (e.g., the artist who opened books)
- **Reference** - Related entity (e.g., the TattooLead for beacon notifications)

## Installation

The package is already installed and configured:

```bash
composer require spatie/laravel-notification-log
```

Configuration published to `config/notification-log.php`.

## Configuration

```php
// config/notification-log.php

return [
    'model' => Spatie\NotificationLog\Models\NotificationLogItem::class,

    // Logs are kept for 90 days, then auto-pruned
    'prune_after_days' => 90,

    // All notifications are logged by default
    'log_all_by_default' => true,
];
```

## Database

### `notification_log_items` table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `notification_type` | string | Class name of the notification |
| `notifiable_id` | bigint | ID of who received it |
| `notifiable_type` | string | Model class of recipient |
| `channel` | string | Delivery channel (mail, database, etc.) |
| `fingerprint` | string | Optional unique identifier |
| `extra` | json | Custom data from `logExtra()` |
| `anonymous_notifiable_properties` | json | For anonymous notifiables |
| `confirmed_at` | datetime | When delivery was confirmed |
| `created_at` | datetime | When notification was sent |

## Adding Logging to Notifications

Each notification should implement a `logExtra()` method to add tracking data:

```php
class BooksOpenNotification extends Notification
{
    public const EVENT_TYPE = 'books_open';

    public function __construct(
        public User $artist
    ) {}

    // ... via() and toMail() methods ...

    /**
     * Extra data to log with this notification.
     */
    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->artist->id,
            'sender_type' => User::class,
        ];
    }
}
```

### With a Reference Entity

For notifications tied to a specific entity (like a TattooLead or Appointment):

```php
public function logExtra(): array
{
    return [
        'event_type' => self::EVENT_TYPE,
        'sender_id' => $this->client->id,
        'sender_type' => User::class,
        'reference_id' => $this->lead->id,
        'reference_type' => TattooLead::class,
    ];
}
```

## Event Types

| Event Type | Notification Class | Sender | Reference |
|------------|-------------------|--------|-----------|
| `books_open` | BooksOpenNotification | Artist who opened books | - |
| `beacon_request` | TattooBeaconNotification | Client seeking tattoo | TattooLead |
| `booking_request` | BookingRequestNotification | Client requesting booking | Appointment |
| `booking_accepted` | BookingAcceptedNotification | Artist who accepted | Appointment |
| `booking_declined` | BookingDeclinedNotification | Artist who declined | Appointment |

## Querying Notification Logs

### Using the Model Directly

```php
use Spatie\NotificationLog\Models\NotificationLogItem;

// Count notifications by event type and sender
$count = NotificationLogItem::query()
    ->whereJsonContains('extra->event_type', 'books_open')
    ->whereJsonContains('extra->sender_id', $artistId)
    ->count();

// Count unique recipients
$uniqueRecipients = NotificationLogItem::query()
    ->whereJsonContains('extra->event_type', 'beacon_request')
    ->whereJsonContains('extra->reference_id', $leadId)
    ->distinct('notifiable_id')
    ->count('notifiable_id');

// Get latest notification for a user
$latest = NotificationLogItem::latestFor($user, notificationType: 'BooksOpenNotification');
```

### Using NotificationStatsService

```php
use App\Services\NotificationStatsService;

$stats = app(NotificationStatsService::class);

// Get books_open stats for an artist
$booksOpenStats = $stats->getBooksOpenStats($artistId);
// Returns: ['total_sent' => 15, 'unique_recipients' => 15, 'last_sent_at' => ...]

// Get beacon stats for a tattoo lead
$beaconStats = $stats->getBeaconStats($tattooId);

// Count by sender
$count = $stats->countBySender('books_open', $artistId);

// Count unique recipients
$unique = $stats->countUniqueRecipientsBySender('books_open', $artistId);
```

## Preventing Duplicate Notifications

Use the logged data to check if a notification was recently sent:

```php
$recentlySent = NotificationLogItem::latestFor(
    $user,
    notificationType: BooksOpenNotification::class,
    after: now()->subHours(24)
);

if (!$recentlySent) {
    $user->notify(new BooksOpenNotification($artist));
}
```

## Pruning Old Logs

Logs are automatically pruned after 90 days (configurable). Ensure the prune command runs:

```bash
# Add to scheduler in app/Console/Kernel.php
$schedule->command('model:prune')->daily();
```

Or run manually:
```bash
php artisan model:prune --model="Spatie\NotificationLog\Models\NotificationLogItem"
```

## Files

### Configuration
- `config/notification-log.php` - Package configuration

### Service
- `App\Services\NotificationStatsService` - Helper methods for querying stats

### Notifications with logExtra()
- `App\Notifications\BooksOpenNotification`
- `App\Notifications\TattooBeaconNotification`
- `App\Notifications\BookingRequestNotification`
- `App\Notifications\BookingAcceptedNotification`
- `App\Notifications\BookingDeclinedNotification`

## Usage Examples

### Show artist how many people were notified when they opened books

```php
// In ArtistController or ArtistDashboardController
$stats = app(NotificationStatsService::class);
$booksOpenStats = $stats->getBooksOpenStats($artist->id);

return response()->json([
    'wishlist_users_notified' => $booksOpenStats['total_sent'],
]);
```

### Show client how many artists were notified about their beacon

```php
// In TattooLeadController::status()
$artistsNotified = NotificationLogItem::query()
    ->whereJsonContains('extra->event_type', 'beacon_request')
    ->whereJsonContains('extra->reference_id', $lead->id)
    ->count();
```

## Debugging

View recent notifications in Telescope or query directly:

```php
// In tinker
NotificationLogItem::latest()->take(10)->get(['notification_type', 'notifiable_id', 'channel', 'extra', 'created_at']);
```
