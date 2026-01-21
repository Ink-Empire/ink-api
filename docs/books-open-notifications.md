# Books Open Notifications

When an artist opens their books, users who have added them to their wishlist (with notifications enabled) are automatically notified via email.

## Overview

1. Artist updates their settings to `books_open: true`
2. System detects the change from closed → open
3. `NotifyWishlistUsersOfBooksOpen` job is dispatched
4. Job queries `artist_wishlists` for users with `notify_booking_open = true` and `notified_at = null`
5. Each user receives a `BooksOpenNotification` email
6. `notified_at` timestamp is updated to prevent duplicate notifications

## Database

### `artist_wishlists` table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | bigint | The user who added the artist |
| `artist_id` | bigint | The artist on the wishlist |
| `notify_booking_open` | boolean | Whether to notify when books open |
| `notified_at` | datetime | When last notified (prevents duplicates) |
| `created_at` | datetime | When added to wishlist |

### `artist_settings` table

| Column | Type | Description |
|--------|------|-------------|
| `artist_id` | bigint | The artist |
| `books_open` | boolean | Whether currently accepting bookings |
| ... | ... | Other settings |

## Flow

### Trigger Point

In `ArtistController::updateSettings()`:

```php
// Check if books_open is being changed from false to true
$existingSettings = ArtistSettings::where('artist_id', $artist->id)->first();
$wasBooksClosed = !$existingSettings || !$existingSettings->books_open;
$isOpeningBooks = !empty($settingsData['books_open']) && $wasBooksClosed;

// ... update settings ...

// Notify wishlist users if books just opened
if ($isOpeningBooks) {
    NotifyWishlistUsersOfBooksOpen::dispatch($artist->id);
}
```

### Job: NotifyWishlistUsersOfBooksOpen

Located at `App\Jobs\NotifyWishlistUsersOfBooksOpen`

```php
public function handle(): void
{
    $artist = User::find($this->artistId);

    // Find wishlist entries where:
    // - notify_booking_open = true (user wants notifications)
    // - notified_at is null (hasn't been notified yet)
    $wishlistEntries = ArtistWishlist::where('artist_id', $this->artistId)
        ->where('notify_booking_open', true)
        ->whereNull('notified_at')
        ->get();

    foreach ($wishlistEntries as $entry) {
        $user = User::find($entry->user_id);
        $user->notify(new BooksOpenNotification($artist));

        // Mark as notified to prevent duplicates
        $entry->update(['notified_at' => now()]);
    }
}
```

### Notification: BooksOpenNotification

Located at `App\Notifications\BooksOpenNotification`

- **Subject:** "{Artist Name} has opened their books! - InkedIn"
- **Content:** Encourages user to schedule a consultation
- **CTA:** Link to artist's profile

Includes `logExtra()` for tracking:
```php
public function logExtra(): array
{
    return [
        'event_type' => 'books_open',
        'sender_id' => $this->artist->id,
        'sender_type' => User::class,
    ];
}
```

### Email Template

Located at `resources/views/mail/books-open.blade.php`

Content:
- "Your wishlist artist {name} has opened their books!"
- "Schedule your consultation while they are open!"
- Button: "View Artist Profile"

## Preventing Duplicate Notifications

Users are only notified **once** per artist until:
1. They remove and re-add the artist to their wishlist
2. Their `notified_at` is manually reset to null
3. They toggle `notify_booking_open` off and back on (future feature)

This is controlled by the `whereNull('notified_at')` condition in the job.

## Querying Notification Stats

```php
use Spatie\NotificationLog\Models\NotificationLogItem;

// How many users were notified when artist opened books?
$count = NotificationLogItem::query()
    ->whereJsonContains('extra->event_type', 'books_open')
    ->whereJsonContains('extra->sender_id', $artistId)
    ->count();
```

## Files

| File | Purpose |
|------|---------|
| `App\Http\Controllers\ArtistController` | Detects books_open change, dispatches job |
| `App\Jobs\NotifyWishlistUsersOfBooksOpen` | Finds and notifies wishlist users |
| `App\Notifications\BooksOpenNotification` | The notification class |
| `resources/views/mail/books-open.blade.php` | Email template |
| `App\Models\ArtistWishlist` | Wishlist model with notification preferences |

## API Endpoints

### Update Artist Settings
```
PUT /api/artists/{id}/settings
```

**Request:**
```json
{
  "books_open": true
}
```

When `books_open` changes from `false` to `true`, notifications are automatically sent.

## Future Enhancements

### Artist Opt-Out (Planned)

Artists should be able to opt out of triggering these notifications to their followers.

**Proposed implementation:**

1. Add `notify_followers_on_books_open` boolean to `artist_settings` table:
   ```php
   $table->boolean('notify_followers_on_books_open')->default(true);
   ```

2. Update `ArtistController::updateSettings()` to check this setting:
   ```php
   if ($isOpeningBooks && $settings->notify_followers_on_books_open) {
       NotifyWishlistUsersOfBooksOpen::dispatch($artist->id);
   }
   ```

3. Add UI toggle in artist dashboard settings

**Use cases for opting out:**
- Artist is only briefly opening books for existing clients
- Artist doesn't want to be overwhelmed with inquiries
- Artist prefers to announce openings through their own channels

### Re-notification Option (Planned)

Allow users to be notified again the next time an artist opens books:

1. Reset `notified_at` to null when artist closes books
2. Or add a "Notify me again next time" option in the email

### Notification Preferences (Planned)

Let users choose notification frequency:
- Every time books open
- Once per month maximum
- Never (but keep on wishlist)

## Testing

```php
// In tinker, simulate books opening
$artist = User::find($artistId);
NotifyWishlistUsersOfBooksOpen::dispatch($artist->id);

// Check who would be notified (dry run)
ArtistWishlist::where('artist_id', $artistId)
    ->where('notify_booking_open', true)
    ->whereNull('notified_at')
    ->with('user')
    ->get()
    ->pluck('user.email');
```
