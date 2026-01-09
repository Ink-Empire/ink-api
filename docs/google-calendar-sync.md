# Google Calendar Sync Implementation

## Overview

Artists can connect their Google Calendar to InkedIn for two-way sync:
1. InkedIn appointments sync TO their Google Calendar
2. Artists can create personal/blocking events on InkedIn that sync to Google
3. Real-time updates via webhooks keep calendars in sync

---

## Database Schema

### `calendar_connections` Table

Stores OAuth credentials and sync state for connected Google accounts.

```sql
CREATE TABLE calendar_connections (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(255) DEFAULT 'google',
    provider_account_id VARCHAR(255),
    provider_email VARCHAR(255),
    calendar_id VARCHAR(255) NULL,
    access_token TEXT,
    refresh_token TEXT,
    token_expires_at TIMESTAMP NULL,
    sync_token VARCHAR(255) NULL,
    last_synced_at TIMESTAMP NULL,
    sync_enabled TINYINT(1) DEFAULT 1,
    webhook_channel_id VARCHAR(255) NULL,
    webhook_resource_id VARCHAR(255) NULL,
    webhook_expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY (user_id, provider),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### `appointments` Table Modifications

```sql
-- Added google_event_id for tracking synced events
ALTER TABLE appointments ADD COLUMN google_event_id VARCHAR(255) NULL;
ALTER TABLE appointments ADD INDEX (google_event_id);

-- Made client_id nullable for personal/blocking events
ALTER TABLE appointments MODIFY client_id BIGINT UNSIGNED NULL;

-- Added additional event types
ALTER TABLE appointments MODIFY type ENUM('tattoo', 'consultation', 'appointment', 'other') DEFAULT 'tattoo';
```

---

## Models

### `app/Models/CalendarConnection.php`

- Stores OAuth tokens (encrypted at rest using Laravel's Crypt)
- Tracks sync state with `sync_token` for delta syncs
- Manages webhook subscription info
- Key methods:
  - `isTokenExpired()` - Check if access token needs refresh
  - `needsTokenRefresh()` - Returns true if token expires within 5 minutes

### `app/Models/User.php` Relationships

```php
public function calendarConnection(): HasOne
{
    return $this->hasOne(CalendarConnection::class)->where('provider', 'google');
}

public function hasGoogleCalendarConnected(): bool
{
    return $this->calendarConnection()->exists();
}
```

---

## Services

### `app/Services/GoogleCalendarService.php`

Core service handling all Google Calendar API interactions:

**OAuth Methods:**
- `getAuthUrl($state)` - Generate OAuth authorization URL
- `exchangeCode($code)` - Exchange auth code for tokens
- `refreshToken($connection)` - Refresh expired access tokens

**Calendar Operations:**
- `initializeWithConnection($connection)` - Set up authenticated client
- `getPrimaryCalendarId()` - Get user's primary calendar ID
- `syncEvents($connection, $fullSync)` - Sync events from Google (supports delta sync via sync tokens)

**Event Management:**
- `createEventFromAppointment($connection, $appointment)` - Create Google event from InkedIn appointment
- `updateEventFromAppointment($connection, $appointment)` - Update existing Google event
- `deleteEvent($connection, $eventId)` - Delete Google event

**Webhooks:**
- `setupWebhook($connection)` - Register for push notifications
- `stopWebhook($connection)` - Unregister webhook

---

## Controllers

### `app/Http/Controllers/CalendarOAuthController.php`

Handles OAuth flow and calendar management:

| Method | Route | Description |
|--------|-------|-------------|
| `getAuthUrl` | `GET /api/calendar/auth-url` | Returns Google OAuth URL |
| `handleCallback` | `GET /api/calendar/callback` | Processes OAuth callback, stores tokens |
| `status` | `GET /api/calendar/status` | Returns connection status and email |
| `disconnect` | `POST /api/calendar/disconnect` | Removes calendar connection |
| `toggleSync` | `POST /api/calendar/toggle-sync` | Enable/disable sync |
| `triggerSync` | `POST /api/calendar/sync` | Manually trigger sync |
| `getEvents` | `GET /api/calendar/events` | Get synced external events |

### `app/Http/Controllers/CalendarWebhookController.php`

Handles Google Calendar push notifications:

| Method | Route | Description |
|--------|-------|-------------|
| `handleGoogleWebhook` | `POST /api/webhooks/google-calendar` | Receives push notifications, triggers sync |

Includes throttling (max 1 sync per minute per connection) to prevent excessive API calls.

### `app/Http/Controllers/AppointmentController.php`

Appointment management with calendar integration:

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `getArtistAppointments` | `POST /api/artists/appointments` | Public | Get all appointments for an artist's calendar |
| `createEvent` | `POST /api/appointments/event` | Required | Create personal/blocking event |
| `update` | `PUT /api/appointments/{id}` | Required | Update appointment |
| `delete` | `DELETE /api/appointments/{id}` | Required | Delete appointment |

---

## Jobs

### `app/Jobs/SyncUserCalendar.php`

Background job to sync Google Calendar events to InkedIn:
- Uses `WithoutOverlapping` middleware to prevent concurrent syncs
- Supports delta sync via Google's sync tokens
- Handles 410 Gone errors by triggering full resync

### `app/Jobs/SyncAppointmentToGoogle.php`

Syncs InkedIn appointments to Google Calendar:
- Dispatched when appointments are created/updated
- Actions: `create`, `update`, `delete`
- Skips if artist doesn't have calendar connected or sync disabled

---

## API Routes

```php
// Public routes
Route::post('/artists/appointments', [AppointmentController::class, 'getArtistAppointments']);

// Protected routes (require auth:sanctum)
Route::prefix('appointments')->group(function () {
    Route::post('/event', [AppointmentController::class, 'createEvent']);
    Route::put('/{id}', [AppointmentController::class, 'update']);
    Route::delete('/{id}', [AppointmentController::class, 'delete']);
});

Route::prefix('calendar')->group(function () {
    Route::get('/auth-url', [CalendarOAuthController::class, 'getAuthUrl']);
    Route::get('/status', [CalendarOAuthController::class, 'status']);
    Route::get('/events', [CalendarOAuthController::class, 'getEvents']);
    Route::post('/disconnect', [CalendarOAuthController::class, 'disconnect']);
    Route::post('/toggle-sync', [CalendarOAuthController::class, 'toggleSync']);
    Route::post('/sync', [CalendarOAuthController::class, 'triggerSync']);
});

// OAuth callback (no auth - user redirected from Google)
Route::get('/calendar/callback', [CalendarOAuthController::class, 'handleCallback']);

// Webhook (no auth - verified by channel ID)
Route::post('/webhooks/google-calendar', [CalendarWebhookController::class, 'handleGoogleWebhook']);
```

---

## Frontend Integration

### Hooks

**`useArtistAppointments.ts`**
```typescript
function useArtistAppointments(artistId, options) {
  // Returns:
  // - appointments: AppointmentType[]
  // - loading: boolean
  // - error: Error | null
  // - refresh: () => Promise<void>
  // - deleteAppointment: (id) => Promise<boolean>
}
```

### Components

**`ArtistProfileCalendar.tsx`**
- Displays artist's calendar with appointments
- Shows gold dot indicators on days with appointments
- Day modal shows appointment details with delete (X) button
- Supports creating new events via click/drag

### API Response Format

```typescript
interface AppointmentType {
  id: number | string;
  title: string;
  start: string;  // ISO datetime: "2026-01-23T10:00:00"
  end: string;    // ISO datetime: "2026-01-23T12:00:00"
  allDay: boolean;
  status: 'pending' | 'booked' | 'completed' | 'cancelled';
  extendedProps: {
    status: string;
    description?: string;
    clientName?: string | null;
    artistName?: string | null;
    studioName?: string;
  };
  client?: ClientResource;
  artist?: BriefArtistResource;
}
```

---

## Configuration

### Environment Variables

```env
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://api.inkedin.dev/api/calendar/callback
```

### `config/services.php`

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

---

## Google Cloud Console Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create/select project
3. Enable "Google Calendar API"
4. Create OAuth 2.0 credentials (Web application)
5. Add authorized redirect URI: `https://api.inkedin.dev/api/calendar/callback`
6. Configure OAuth consent screen with calendar scopes

---

## Data Flow

### Connecting Google Calendar

```
1. User clicks "Connect Google Calendar"
2. Frontend calls GET /api/calendar/auth-url
3. User redirected to Google OAuth
4. Google redirects to /api/calendar/callback with code
5. Backend exchanges code for tokens
6. Backend stores CalendarConnection
7. Backend sets up webhook for push notifications
8. Initial sync job queued
```

### Creating an Event on InkedIn

```
1. User creates event via calendar UI
2. Frontend calls POST /api/appointments/event
3. Backend creates Appointment record
4. If sync_to_google=true, dispatches SyncAppointmentToGoogle job
5. Job creates event in Google Calendar
6. google_event_id stored on appointment
```

### Receiving Google Calendar Updates

```
1. Google sends POST to /api/webhooks/google-calendar
2. Backend identifies connection via channel ID
3. Throttle check (max 1 sync/minute)
4. SyncUserCalendar job dispatched
5. Delta sync pulls only changed events
```

---

## Key Files

### Backend (ink-api)

| File | Purpose |
|------|---------|
| `app/Models/CalendarConnection.php` | OAuth tokens and sync state |
| `app/Services/GoogleCalendarService.php` | Google Calendar API wrapper |
| `app/Http/Controllers/CalendarOAuthController.php` | OAuth flow and settings |
| `app/Http/Controllers/CalendarWebhookController.php` | Push notification handler |
| `app/Http/Controllers/AppointmentController.php` | Appointment CRUD |
| `app/Http/Resources/AppointmentResource.php` | API response formatting |
| `app/Jobs/SyncUserCalendar.php` | Sync from Google |
| `app/Jobs/SyncAppointmentToGoogle.php` | Sync to Google |

### Frontend (nextjs)

| File | Purpose |
|------|---------|
| `hooks/useArtistAppointments.ts` | Fetch and manage appointments |
| `components/ArtistProfileCalendar.tsx` | Calendar display and interaction |

---

## Troubleshooting

### Common Issues

**Events not syncing to Google**
- Check if `sync_enabled` is true on CalendarConnection
- Verify Google credentials in .env
- Check Laravel logs for API errors

### Cache Commands

```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```
