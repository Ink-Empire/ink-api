# Google Calendar Sync Implementation Plan

## Overview

Allow artists to connect their Google Calendar so that:
1. Their existing appointments appear as "busy" times on InkedIn
2. InkedIn appointments sync TO their Google Calendar
3. Changes in either direction stay in sync

---

## Phase 1: Database Schema

### Migration: `create_calendar_connections_table.php`

```php
Schema::create('calendar_connections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('provider')->default('google'); // google, outlook (future)
    $table->string('provider_account_id'); // Google account ID
    $table->string('provider_email');
    $table->string('calendar_id')->nullable(); // primary calendar ID
    $table->text('access_token');
    $table->text('refresh_token');
    $table->timestamp('token_expires_at');
    $table->string('sync_token')->nullable(); // for delta sync
    $table->timestamp('last_synced_at')->nullable();
    $table->boolean('sync_enabled')->default(true);

    // Webhook fields
    $table->string('webhook_channel_id')->nullable();
    $table->string('webhook_resource_id')->nullable();
    $table->timestamp('webhook_expires_at')->nullable();

    $table->timestamps();

    $table->unique(['user_id', 'provider']);
});
```

### Migration: `create_external_calendar_events_table.php`

```php
Schema::create('external_calendar_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('calendar_connection_id')->constrained()->onDelete('cascade');
    $table->foreignId('appointment_id')->nullable()->constrained()->onDelete('set null');
    $table->string('vendor_event_id'); // Google event ID
    $table->string('title')->nullable();
    $table->timestamp('starts_at');
    $table->timestamp('ends_at');
    $table->boolean('all_day')->default(false);
    $table->string('status')->default('confirmed'); // confirmed, tentative, cancelled
    $table->enum('source', ['google', 'inkedin'])->default('google');
    $table->json('metadata')->nullable(); // store extra Google fields
    $table->timestamps();

    $table->unique(['calendar_connection_id', 'vendor_event_id']);
    $table->index(['starts_at', 'ends_at']);
});
```

### Migration: `add_google_event_id_to_appointments_table.php`

```php
Schema::table('appointments', function (Blueprint $table) {
    $table->string('google_event_id')->nullable()->after('status');
    $table->index('google_event_id');
});
```

---

## Phase 2: Models

### `app/Models/CalendarConnection.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class CalendarConnection extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_account_id',
        'provider_email',
        'calendar_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'sync_token',
        'last_synced_at',
        'sync_enabled',
        'webhook_channel_id',
        'webhook_resource_id',
        'webhook_expires_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'webhook_expires_at' => 'datetime',
        'sync_enabled' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    // Encrypt tokens at rest
    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setRefreshTokenAttribute($value): void
    {
        $this->attributes['refresh_token'] = Crypt::encryptString($value);
    }

    public function getRefreshTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function externalEvents(): HasMany
    {
        return $this->hasMany(ExternalCalendarEvent::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at->isPast();
    }

    public function needsTokenRefresh(): bool
    {
        // Refresh if expiring within 5 minutes
        return $this->token_expires_at->subMinutes(5)->isPast();
    }
}
```

### `app/Models/ExternalCalendarEvent.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCalendarEvent extends Model
{
    protected $fillable = [
        'calendar_connection_id',
        'appointment_id',
        'vendor_event_id',
        'title',
        'starts_at',
        'ends_at',
        'all_day',
        'status',
        'source',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'metadata' => 'array',
    ];

    public function calendarConnection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
```

### Update `app/Models/User.php`

```php
// Add relationship
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

## Phase 3: Google Calendar Service

### `app/Services/GoogleCalendarService.php`

```php
<?php

namespace App\Services;

use App\Models\CalendarConnection;
use App\Models\ExternalCalendarEvent;
use App\Models\Appointment;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private GoogleClient $client;
    private ?GoogleCalendar $calendar = null;

    public function __construct()
    {
        $this->client = new GoogleClient();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setScopes([
            GoogleCalendar::CALENDAR_READONLY,
            GoogleCalendar::CALENDAR_EVENTS,
        ]);
    }

    /**
     * Get OAuth authorization URL
     */
    public function getAuthUrl(string $state = null): string
    {
        if ($state) {
            $this->client->setState($state);
        }
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCode(string $code): array
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \Exception('Google OAuth error: ' . $token['error_description']);
        }

        return $token;
    }

    /**
     * Initialize client with connection credentials
     */
    public function initializeWithConnection(CalendarConnection $connection): self
    {
        // Refresh token if needed
        if ($connection->needsTokenRefresh()) {
            $this->refreshToken($connection);
        }

        $this->client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
            'expires_in' => $connection->token_expires_at->diffInSeconds(now()),
        ]);

        $this->calendar = new GoogleCalendar($this->client);

        return $this;
    }

    /**
     * Refresh access token
     */
    public function refreshToken(CalendarConnection $connection): void
    {
        $this->client->refreshToken($connection->refresh_token);
        $newToken = $this->client->getAccessToken();

        $connection->update([
            'access_token' => $newToken['access_token'],
            'token_expires_at' => now()->addSeconds($newToken['expires_in']),
            // Refresh token may or may not be returned
            'refresh_token' => $newToken['refresh_token'] ?? $connection->refresh_token,
        ]);
    }

    /**
     * Get user's primary calendar ID
     */
    public function getPrimaryCalendarId(): string
    {
        return $this->calendar->calendars->get('primary')->getId();
    }

    /**
     * Sync events from Google Calendar
     */
    public function syncEvents(CalendarConnection $connection, bool $fullSync = false): array
    {
        $this->initializeWithConnection($connection);

        $params = [
            'singleEvents' => true,
            'maxResults' => 250,
            'orderBy' => 'startTime',
        ];

        // Delta sync vs full sync
        if ($connection->sync_token && !$fullSync) {
            $params['syncToken'] = $connection->sync_token;
        } else {
            // Full sync: get events from now to 3 months ahead
            $params['timeMin'] = now()->toRfc3339String();
            $params['timeMax'] = now()->addMonths(3)->toRfc3339String();
        }

        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $pageToken = null;

        try {
            do {
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $events = $this->calendar->events->listEvents(
                    $connection->calendar_id ?? 'primary',
                    $params
                );

                foreach ($events->getItems() as $googleEvent) {
                    $result = $this->syncSingleEvent($connection, $googleEvent);
                    $stats[$result]++;
                }

                $pageToken = $events->getNextPageToken();

            } while ($pageToken);

            // Save new sync token
            if ($newSyncToken = $events->getNextSyncToken()) {
                $connection->update([
                    'sync_token' => $newSyncToken,
                    'last_synced_at' => now(),
                ]);
            }

        } catch (\Google\Service\Exception $e) {
            // 410 Gone = sync token expired, need full resync
            if ($e->getCode() === 410) {
                Log::info("Sync token expired for connection {$connection->id}, doing full resync");
                $connection->update(['sync_token' => null]);
                return $this->syncEvents($connection, fullSync: true);
            }
            throw $e;
        }

        return $stats;
    }

    /**
     * Sync a single Google event to our database
     */
    private function syncSingleEvent(CalendarConnection $connection, GoogleEvent $googleEvent): string
    {
        $vendorId = $googleEvent->getId();

        // Handle cancelled/deleted events
        if ($googleEvent->getStatus() === 'cancelled') {
            $deleted = ExternalCalendarEvent::where('calendar_connection_id', $connection->id)
                ->where('vendor_event_id', $vendorId)
                ->delete();
            return $deleted ? 'deleted' : 'updated';
        }

        // Skip all-day events that span multiple days (likely holidays/time-off)
        $start = $googleEvent->getStart();
        $end = $googleEvent->getEnd();

        // Parse start/end times
        if ($start->getDateTime()) {
            $startsAt = Carbon::parse($start->getDateTime());
            $endsAt = Carbon::parse($end->getDateTime());
            $allDay = false;
        } else {
            // All-day event
            $startsAt = Carbon::parse($start->getDate())->startOfDay();
            $endsAt = Carbon::parse($end->getDate())->startOfDay();
            $allDay = true;
        }

        // Skip events created by InkedIn (they have our appointment_id in metadata)
        $extendedProps = $googleEvent->getExtendedProperties();
        if ($extendedProps && $extendedProps->getPrivate()) {
            $private = $extendedProps->getPrivate();
            if (isset($private['inkedin_appointment_id'])) {
                return 'updated'; // Skip, we created this
            }
        }

        $existing = ExternalCalendarEvent::where('calendar_connection_id', $connection->id)
            ->where('vendor_event_id', $vendorId)
            ->first();

        $data = [
            'calendar_connection_id' => $connection->id,
            'vendor_event_id' => $vendorId,
            'title' => $googleEvent->getSummary() ?? '(Busy)',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'status' => $googleEvent->getStatus() ?? 'confirmed',
            'source' => 'google',
            'metadata' => [
                'location' => $googleEvent->getLocation(),
                'description' => $googleEvent->getDescription(),
                'html_link' => $googleEvent->getHtmlLink(),
            ],
        ];

        if ($existing) {
            $existing->update($data);
            return 'updated';
        } else {
            ExternalCalendarEvent::create($data);
            return 'created';
        }
    }

    /**
     * Create a Google Calendar event from an InkedIn appointment
     */
    public function createEventFromAppointment(CalendarConnection $connection, Appointment $appointment): string
    {
        $this->initializeWithConnection($connection);

        $event = new GoogleEvent([
            'summary' => $this->buildEventTitle($appointment),
            'description' => $this->buildEventDescription($appointment),
            'start' => [
                'dateTime' => $appointment->date->setTimeFromTimeString($appointment->start_time)->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ],
            'end' => [
                'dateTime' => $appointment->date->setTimeFromTimeString($appointment->end_time)->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ],
            'extendedProperties' => [
                'private' => [
                    'inkedin_appointment_id' => (string) $appointment->id,
                ],
            ],
        ]);

        $createdEvent = $this->calendar->events->insert(
            $connection->calendar_id ?? 'primary',
            $event
        );

        // Store the Google event ID on the appointment
        $appointment->update(['google_event_id' => $createdEvent->getId()]);

        return $createdEvent->getId();
    }

    /**
     * Update a Google Calendar event when appointment changes
     */
    public function updateEventFromAppointment(CalendarConnection $connection, Appointment $appointment): void
    {
        if (!$appointment->google_event_id) {
            // No linked event, create one instead
            $this->createEventFromAppointment($connection, $appointment);
            return;
        }

        $this->initializeWithConnection($connection);

        try {
            $event = $this->calendar->events->get(
                $connection->calendar_id ?? 'primary',
                $appointment->google_event_id
            );

            $event->setSummary($this->buildEventTitle($appointment));
            $event->setDescription($this->buildEventDescription($appointment));
            $event->setStart(new \Google\Service\Calendar\EventDateTime([
                'dateTime' => $appointment->date->setTimeFromTimeString($appointment->start_time)->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]));
            $event->setEnd(new \Google\Service\Calendar\EventDateTime([
                'dateTime' => $appointment->date->setTimeFromTimeString($appointment->end_time)->toRfc3339String(),
                'timeZone' => config('app.timezone'),
            ]));

            $this->calendar->events->update(
                $connection->calendar_id ?? 'primary',
                $appointment->google_event_id,
                $event
            );
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 404) {
                // Event was deleted in Google, recreate it
                $appointment->update(['google_event_id' => null]);
                $this->createEventFromAppointment($connection, $appointment);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Delete a Google Calendar event when appointment is cancelled
     */
    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $this->initializeWithConnection($connection);

        try {
            $this->calendar->events->delete(
                $connection->calendar_id ?? 'primary',
                $eventId
            );
        } catch (\Google\Service\Exception $e) {
            // 404 = already deleted, that's fine
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Set up webhook for push notifications
     */
    public function setupWebhook(CalendarConnection $connection): void
    {
        $this->initializeWithConnection($connection);

        $channelId = 'inkedin-' . $connection->id . '-' . time();
        $webhookUrl = config('app.url') . '/api/webhooks/google-calendar';

        $channel = new \Google\Service\Calendar\Channel([
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $webhookUrl,
            'expiration' => now()->addDays(7)->getTimestampMs(),
        ]);

        $response = $this->calendar->events->watch(
            $connection->calendar_id ?? 'primary',
            $channel
        );

        $connection->update([
            'webhook_channel_id' => $response->getId(),
            'webhook_resource_id' => $response->getResourceId(),
            'webhook_expires_at' => Carbon::createFromTimestampMs($response->getExpiration()),
        ]);
    }

    /**
     * Stop webhook notifications
     */
    public function stopWebhook(CalendarConnection $connection): void
    {
        if (!$connection->webhook_channel_id || !$connection->webhook_resource_id) {
            return;
        }

        $this->initializeWithConnection($connection);

        try {
            $channel = new \Google\Service\Calendar\Channel([
                'id' => $connection->webhook_channel_id,
                'resourceId' => $connection->webhook_resource_id,
            ]);

            $this->calendar->channels->stop($channel);
        } catch (\Exception $e) {
            // Ignore errors when stopping webhooks
            Log::warning("Failed to stop webhook for connection {$connection->id}: " . $e->getMessage());
        }

        $connection->update([
            'webhook_channel_id' => null,
            'webhook_resource_id' => null,
            'webhook_expires_at' => null,
        ]);
    }

    private function buildEventTitle(Appointment $appointment): string
    {
        $clientName = $appointment->client?->name ?? 'Client';
        $type = $appointment->type === 'consultation' ? 'Consultation' : 'Tattoo Appointment';
        return "{$type} - {$clientName}";
    }

    private function buildEventDescription(Appointment $appointment): string
    {
        $lines = ["Booked via InkedIn"];

        if ($appointment->client) {
            $lines[] = "Client: {$appointment->client->name}";
            if ($appointment->client->phone) {
                $lines[] = "Phone: {$appointment->client->phone}";
            }
        }

        if ($appointment->description) {
            $lines[] = "";
            $lines[] = "Notes: {$appointment->description}";
        }

        return implode("\n", $lines);
    }
}
```

---

## Phase 4: OAuth Controller

### `app/Http/Controllers/CalendarOAuthController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use App\Jobs\SyncUserCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CalendarOAuthController extends Controller
{
    public function __construct(
        private GoogleCalendarService $googleCalendar
    ) {}

    /**
     * Get the Google OAuth URL
     */
    public function getAuthUrl(Request $request): JsonResponse
    {
        $state = encrypt($request->user()->id);
        $url = $this->googleCalendar->getAuthUrl($state);

        return response()->json(['url' => $url]);
    }

    /**
     * Handle OAuth callback
     */
    public function handleCallback(Request $request): JsonResponse
    {
        try {
            $code = $request->input('code');
            $state = $request->input('state');

            if (!$code) {
                return response()->json(['error' => 'No authorization code provided'], 400);
            }

            // Decrypt user ID from state
            $userId = decrypt($state);
            $user = \App\Models\User::findOrFail($userId);

            // Exchange code for tokens
            $tokens = $this->googleCalendar->exchangeCode($code);

            // Get user info from Google
            $client = new \Google\Client();
            $client->setAccessToken($tokens['access_token']);
            $oauth2 = new \Google\Service\Oauth2($client);
            $googleUser = $oauth2->userinfo->get();

            // Create or update calendar connection
            $connection = CalendarConnection::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => 'google',
                ],
                [
                    'provider_account_id' => $googleUser->getId(),
                    'provider_email' => $googleUser->getEmail(),
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                    'sync_token' => null, // Reset for fresh sync
                ]
            );

            // Get primary calendar ID
            $this->googleCalendar->initializeWithConnection($connection);
            $calendarId = $this->googleCalendar->getPrimaryCalendarId();
            $connection->update(['calendar_id' => $calendarId]);

            // Queue initial sync
            SyncUserCalendar::dispatch($connection->id);

            // Set up webhook for push notifications
            $this->googleCalendar->setupWebhook($connection);

            return response()->json([
                'success' => true,
                'message' => 'Google Calendar connected successfully',
                'email' => $googleUser->getEmail(),
            ]);

        } catch (\Exception $e) {
            Log::error('Google Calendar OAuth error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to connect Google Calendar',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disconnect Google Calendar
     */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $connection = $user->calendarConnection;

        if (!$connection) {
            return response()->json(['error' => 'No calendar connected'], 404);
        }

        // Stop webhook
        $this->googleCalendar->stopWebhook($connection);

        // Delete connection and events
        $connection->externalEvents()->delete();
        $connection->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get connection status
     */
    public function status(Request $request): JsonResponse
    {
        $connection = $request->user()->calendarConnection;

        if (!$connection) {
            return response()->json([
                'connected' => false,
            ]);
        }

        return response()->json([
            'connected' => true,
            'email' => $connection->provider_email,
            'last_synced_at' => $connection->last_synced_at,
            'sync_enabled' => $connection->sync_enabled,
        ]);
    }

    /**
     * Toggle sync on/off
     */
    public function toggleSync(Request $request): JsonResponse
    {
        $connection = $request->user()->calendarConnection;

        if (!$connection) {
            return response()->json(['error' => 'No calendar connected'], 404);
        }

        $connection->update([
            'sync_enabled' => !$connection->sync_enabled,
        ]);

        return response()->json([
            'sync_enabled' => $connection->sync_enabled,
        ]);
    }

    /**
     * Manually trigger sync
     */
    public function triggerSync(Request $request): JsonResponse
    {
        $connection = $request->user()->calendarConnection;

        if (!$connection) {
            return response()->json(['error' => 'No calendar connected'], 404);
        }

        SyncUserCalendar::dispatch($connection->id);

        return response()->json([
            'success' => true,
            'message' => 'Sync started',
        ]);
    }
}
```

---

## Phase 5: Jobs

### `app/Jobs/SyncUserCalendar.php`

```php
<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncUserCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 300;

    public function __construct(
        public int $connectionId
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->connectionId))->dontRelease(),
        ];
    }

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        $connection = CalendarConnection::find($this->connectionId);

        if (!$connection || !$connection->sync_enabled) {
            return;
        }

        try {
            $stats = $googleCalendar->syncEvents($connection);

            Log::info("Calendar sync completed for connection {$this->connectionId}", $stats);

        } catch (\Exception $e) {
            Log::error("Calendar sync failed for connection {$this->connectionId}: " . $e->getMessage());
            throw $e;
        }
    }
}
```

### `app/Jobs/SyncAppointmentToGoogle.php`

```php
<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAppointmentToGoogle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $appointmentId,
        public string $action = 'upsert' // upsert, delete
    ) {}

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        $appointment = Appointment::with('artist')->find($this->appointmentId);

        if (!$appointment) {
            return;
        }

        $connection = CalendarConnection::where('user_id', $appointment->artist_id)
            ->where('provider', 'google')
            ->where('sync_enabled', true)
            ->first();

        if (!$connection) {
            return;
        }

        if ($this->action === 'delete' && $appointment->google_event_id) {
            $googleCalendar->deleteEvent($connection, $appointment->google_event_id);
        } else {
            $googleCalendar->updateEventFromAppointment($connection, $appointment);
        }
    }
}
```

### `app/Jobs/RefreshCalendarWebhooks.php`

```php
<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshCalendarWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        // Find connections with webhooks expiring in the next 24 hours
        $connections = CalendarConnection::where('sync_enabled', true)
            ->whereNotNull('webhook_expires_at')
            ->where('webhook_expires_at', '<', now()->addDay())
            ->get();

        foreach ($connections as $connection) {
            try {
                // Stop old webhook
                $googleCalendar->stopWebhook($connection);

                // Create new webhook
                $googleCalendar->setupWebhook($connection);

                Log::info("Refreshed webhook for calendar connection {$connection->id}");
            } catch (\Exception $e) {
                Log::error("Failed to refresh webhook for connection {$connection->id}: " . $e->getMessage());
            }
        }
    }
}
```

---

## Phase 6: Webhook Controller

### `app/Http/Controllers/CalendarWebhookController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Jobs\SyncUserCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CalendarWebhookController extends Controller
{
    /**
     * Handle Google Calendar push notification
     */
    public function handleGoogleWebhook(Request $request): Response
    {
        // Google sends these headers
        $channelId = $request->header('X-Goog-Channel-ID');
        $resourceState = $request->header('X-Goog-Resource-State');

        if (!$channelId) {
            return response('Missing channel ID', 400);
        }

        // Ignore sync messages (initial setup confirmation)
        if ($resourceState === 'sync') {
            return response('OK', 200);
        }

        // Find the connection
        $connection = CalendarConnection::where('webhook_channel_id', $channelId)->first();

        if (!$connection) {
            Log::warning("Received webhook for unknown channel: {$channelId}");
            return response('Unknown channel', 404);
        }

        // Throttle: max 1 sync per minute per connection
        $cacheKey = "calendar_webhook_throttle:{$connection->id}";
        if (Cache::has($cacheKey)) {
            Log::debug("Throttled webhook for connection {$connection->id}");
            return response('OK', 200);
        }

        Cache::put($cacheKey, true, 60);

        // Queue sync job
        SyncUserCalendar::dispatch($connection->id);

        return response('OK', 200);
    }
}
```

---

## Phase 7: Routes

### Add to `routes/api.php`

```php
// Calendar integration routes
Route::middleware('auth:sanctum')->prefix('calendar')->group(function () {
    Route::get('/auth-url', [CalendarOAuthController::class, 'getAuthUrl']);
    Route::get('/status', [CalendarOAuthController::class, 'status']);
    Route::post('/disconnect', [CalendarOAuthController::class, 'disconnect']);
    Route::post('/toggle-sync', [CalendarOAuthController::class, 'toggleSync']);
    Route::post('/sync', [CalendarOAuthController::class, 'triggerSync']);
});

// OAuth callback (no auth required - user comes from Google)
Route::get('/calendar/callback', [CalendarOAuthController::class, 'handleCallback']);

// Webhook (no auth - verified by channel ID)
Route::post('/webhooks/google-calendar', [CalendarWebhookController::class, 'handleGoogleWebhook']);
```

---

## Phase 8: Scheduler

### Update `app/Console/Kernel.php`

```php
protected function schedule(Schedule $schedule): void
{
    // Refresh calendar webhooks daily
    $schedule->job(new \App\Jobs\RefreshCalendarWebhooks)
        ->daily()
        ->withoutOverlapping();

    // Periodic sync for all calendars (backup for webhooks)
    $schedule->call(function () {
        CalendarConnection::where('sync_enabled', true)
            ->where(function ($q) {
                $q->whereNull('last_synced_at')
                  ->orWhere('last_synced_at', '<', now()->subHours(6));
            })
            ->each(function ($connection) {
                SyncUserCalendar::dispatch($connection->id);
            });
    })->hourly();
}
```

---

## Phase 9: Configuration

### Add to `config/services.php`

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

### Add to `.env`

```
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://api.inkedin.dev/api/calendar/callback
```

---

## Phase 10: Integration with Appointments

### Update `AppointmentController.php`

When appointments are created/updated/cancelled, dispatch sync job:

```php
// After creating appointment
SyncAppointmentToGoogle::dispatch($appointment->id, 'upsert');

// After updating appointment
SyncAppointmentToGoogle::dispatch($appointment->id, 'upsert');

// After cancelling appointment
SyncAppointmentToGoogle::dispatch($appointment->id, 'delete');
```

Or use Eloquent observers:

### `app/Observers/AppointmentObserver.php`

```php
<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Jobs\SyncAppointmentToGoogle;

class AppointmentObserver
{
    public function created(Appointment $appointment): void
    {
        SyncAppointmentToGoogle::dispatch($appointment->id, 'upsert');
    }

    public function updated(Appointment $appointment): void
    {
        // Only sync if relevant fields changed
        if ($appointment->wasChanged(['date', 'start_time', 'end_time', 'status', 'title'])) {
            $action = $appointment->status === 'cancelled' ? 'delete' : 'upsert';
            SyncAppointmentToGoogle::dispatch($appointment->id, $action);
        }
    }

    public function deleted(Appointment $appointment): void
    {
        if ($appointment->google_event_id) {
            SyncAppointmentToGoogle::dispatch($appointment->id, 'delete');
        }
    }
}
```

---

## Composer Dependencies

```bash
composer require google/apiclient:^2.0
```

---

## Google Cloud Console Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project or select existing
3. Enable "Google Calendar API"
4. Go to "Credentials" → "Create Credentials" → "OAuth 2.0 Client IDs"
5. Application type: "Web application"
6. Add authorized redirect URI: `https://api.inkedin.dev/api/calendar/callback`
7. Copy Client ID and Client Secret to `.env`
8. Configure OAuth consent screen (external, add scopes for calendar)

---

## Implementation Order

1. **Week 1**: Database migrations, models, config
2. **Week 1**: GoogleCalendarService (OAuth + basic sync)
3. **Week 2**: CalendarOAuthController (connect/disconnect)
4. **Week 2**: SyncUserCalendar job + scheduler
5. **Week 3**: Two-way sync (appointments → Google)
6. **Week 3**: Webhooks for real-time updates
7. **Week 4**: Frontend UI for connecting calendar
8. **Week 4**: Testing, edge cases, error handling

---

## Frontend Integration Points

The frontend needs:

1. **Settings page**: "Connect Google Calendar" button
2. **OAuth flow**: Redirect to Google, handle callback
3. **Status display**: Show connected email, last sync time
4. **Toggle**: Enable/disable sync
5. **Calendar view**: Show external events as "busy" blocks (different color)

API endpoints to call:
- `GET /api/calendar/auth-url` → Get OAuth URL, redirect user
- `GET /api/calendar/status` → Check if connected
- `POST /api/calendar/disconnect` → Disconnect
- `POST /api/calendar/toggle-sync` → Enable/disable
- `POST /api/calendar/sync` → Manual sync trigger
