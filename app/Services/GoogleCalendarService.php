<?php

namespace App\Services;

use App\Enums\GoogleOAuthError;
use App\Exceptions\CalendarReauthRequiredException;
use App\Exceptions\CalendarRefreshFailedException;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarEvent;
use App\Notifications\CalendarDisconnectedNotification;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Channel;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Oauth2;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private GoogleClient $client;

    private ?GoogleCalendar $calendar = null;

    public function __construct()
    {
        $this->client = new GoogleClient;
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        // calendar.events is the only calendar scope any call here needs. It
        // covers reading, writing and watching events on whichever calendar is
        // addressed. calendar.readonly would additionally grant every calendar
        // the artist can see, which nothing uses.
        $this->client->setScopes([
            GoogleCalendar::CALENDAR_EVENTS,
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ]);
    }

    /**
     * Get OAuth authorization URL
     */
    public function getAuthUrl(?string $state = null): string
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
            throw new \Exception('Google OAuth error: '.($token['error_description'] ?? $token['error']));
        }

        return $token;
    }

    /**
     * Whether a token exchange actually came back with calendar access.
     *
     * Google's consent screen lets people decline individual permissions, so an
     * exchange can succeed and still return a token with only the identity
     * scopes. Storing one of those gives the artist a connection that looks
     * connected and fails every sync with a 403.
     */
    public function grantsCalendarAccess(array $tokens): bool
    {
        $granted = explode(' ', (string) ($tokens['scope'] ?? ''));

        return in_array(GoogleCalendar::CALENDAR_EVENTS, $granted, true);
    }

    /**
     * Get user info from Google
     */
    public function getUserInfo(string $accessToken): array
    {
        $this->client->setAccessToken($accessToken);
        $oauth2 = new Oauth2($this->client);
        $userInfo = $oauth2->userinfo->get();

        return [
            'id' => $userInfo->getId(),
            'email' => $userInfo->getEmail(),
            'name' => $userInfo->getName(),
        ];
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
            'expires_in' => max(0, $connection->token_expires_at->diffInSeconds(now())),
        ]);

        $this->calendar = new GoogleCalendar($this->client);

        return $this;
    }

    /**
     * Refresh access token
     */
    public function refreshToken(CalendarConnection $connection): void
    {
        $response = (array) $this->client->refreshToken($connection->refresh_token);

        // The Google client is built with http_errors disabled, so OAuth
        // failures come back as data rather than exceptions. invalid_grant is
        // the only answer that means the grant is gone for good. Everything
        // else is Google being unavailable, and disconnecting a working
        // calendar over a bad minute would be worse than retrying.
        if (empty($response['access_token'])) {
            $error = $response['error'] ?? GoogleOAuthError::UNKNOWN;

            if ($error !== GoogleOAuthError::INVALID_GRANT) {
                throw new CalendarRefreshFailedException(
                    "Calendar connection {$connection->id} could not refresh its token: {$error}."
                );
            }

            $this->markForReauth($connection);

            throw new CalendarReauthRequiredException(
                "Calendar connection {$connection->id} requires re-authorisation."
            );
        }

        $connection->update([
            'access_token' => $response['access_token'],
            'token_expires_at' => now()->addSeconds($response['expires_in'] ?? 3600),
            'refresh_token' => $response['refresh_token'] ?? $connection->refresh_token,
        ]);

        Log::info("Refreshed token for calendar connection {$connection->id}");
    }

    /**
     * Takes a genuinely dead connection out of the sync rotation and tells the
     * owner, who is the only one who can reconnect it. Without the notice the
     * calendar just stops syncing and nobody finds out until a double booking.
     */
    private function markForReauth(CalendarConnection $connection): void
    {
        $alreadyFlagged = (bool) $connection->requires_reauth;

        Log::warning("Calendar connection {$connection->id} returned invalid_grant, marking for reauth");

        $connection->update([
            'sync_enabled' => false,
            'requires_reauth' => true,
        ]);

        if (! $alreadyFlagged) {
            $connection->user?->notify(new CalendarDisconnectedNotification($connection));
        }
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
        if ($connection->sync_token && ! $fullSync) {
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

        // Skip events created by InkedIn (they have our appointment_id in metadata)
        $extendedProps = $googleEvent->getExtendedProperties();
        if ($extendedProps) {
            $private = $extendedProps->getPrivate();
            if ($private && isset($private['inkedin_appointment_id'])) {
                return 'updated'; // Skip, we created this
            }
        }

        // Parse start/end times
        $start = $googleEvent->getStart();
        $end = $googleEvent->getEnd();

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
     * Start and end for a Google event, in the artist's own timezone.
     *
     * Appointment date and start_time are stored naive, meaning whatever the
     * artist saw on screen. Parsing them without a zone treats them as UTC,
     * which put a 9am booking on the artist's Google calendar at 5am and would
     * have had clients arriving four hours out.
     */
    public function eventTimes(Appointment $appointment): array
    {
        $timezone = $appointment->artist?->timezone;

        if (! $timezone) {
            Log::warning("Appointment {$appointment->id} has no artist timezone, falling back to ".config('app.timezone'));
            $timezone = config('app.timezone');
        }

        $date = $appointment->date->format('Y-m-d');

        return [
            'start' => [
                'dateTime' => Carbon::parse($date.' '.$appointment->start_time, $timezone)->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => Carbon::parse($date.' '.$appointment->end_time, $timezone)->toRfc3339String(),
                'timeZone' => $timezone,
            ],
        ];
    }

    /**
     * Create a Google Calendar event from an InkedIn appointment
     */
    public function createEventFromAppointment(CalendarConnection $connection, Appointment $appointment): string
    {
        $this->initializeWithConnection($connection);

        $times = $this->eventTimes($appointment);

        $event = new GoogleEvent([
            'summary' => $this->buildEventTitle($appointment),
            'description' => $this->buildEventDescription($appointment),
            'start' => $times['start'],
            'end' => $times['end'],
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

        Log::info("Created Google Calendar event for appointment {$appointment->id}");

        return $createdEvent->getId();
    }

    /**
     * Update a Google Calendar event when appointment changes
     */
    public function updateEventFromAppointment(CalendarConnection $connection, Appointment $appointment): void
    {
        if (! $appointment->google_event_id) {
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

            $times = $this->eventTimes($appointment);

            $event->setSummary($this->buildEventTitle($appointment));
            $event->setDescription($this->buildEventDescription($appointment));
            $event->setStart(new EventDateTime($times['start']));
            $event->setEnd(new EventDateTime($times['end']));

            $this->calendar->events->update(
                $connection->calendar_id ?? 'primary',
                $appointment->google_event_id,
                $event
            );

            Log::info("Updated Google Calendar event for appointment {$appointment->id}");

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
            Log::info("Deleted Google Calendar event {$eventId}");
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

        $channelId = 'inkedin-'.$connection->id.'-'.time();
        $webhookUrl = config('app.url').'/api/webhooks/google-calendar';

        $channel = new Channel([
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

        Log::info("Set up webhook for calendar connection {$connection->id}");
    }

    /**
     * Stop webhook notifications
     */
    public function stopWebhook(CalendarConnection $connection): void
    {
        if (! $connection->webhook_channel_id || ! $connection->webhook_resource_id) {
            return;
        }

        $this->initializeWithConnection($connection);

        try {
            $channel = new Channel([
                'id' => $connection->webhook_channel_id,
                'resourceId' => $connection->webhook_resource_id,
            ]);

            $this->calendar->channels->stop($channel);
            Log::info("Stopped webhook for calendar connection {$connection->id}");
        } catch (\Exception $e) {
            // Ignore errors when stopping webhooks
            Log::warning("Failed to stop webhook for connection {$connection->id}: ".$e->getMessage());
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
        $lines = ['Booked via InkedIn'];

        if ($appointment->client) {
            $lines[] = "Client: {$appointment->client->name}";
            if ($appointment->client->phone) {
                $lines[] = "Phone: {$appointment->client->phone}";
            }
        }

        if ($appointment->description) {
            $lines[] = '';
            $lines[] = "Notes: {$appointment->description}";
        }

        return implode("\n", $lines);
    }

    /**
     * Time ranges on a given date that are already taken by the artist's synced
     * external calendars, so availability does not offer them to clients.
     *
     * Returned as ['start' => 'H:i', 'end' => 'H:i'] clipped to the date. An
     * all-day event returns the whole day.
     */
    public function getExternalBusyRanges(int $userId, string $date): array
    {
        $connectionIds = CalendarConnection::where('user_id', $userId)
            ->where('sync_enabled', true)
            ->pluck('id');

        if ($connectionIds->isEmpty()) {
            return [];
        }

        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();

        $events = ExternalCalendarEvent::whereIn('calendar_connection_id', $connectionIds)
            ->whereNull('appointment_id') // our own appointments already block separately
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get();

        $ranges = [];

        foreach ($events as $event) {
            if ($event->all_day) {
                $ranges[] = ['start' => '00:00', 'end' => '23:59'];

                continue;
            }

            $start = Carbon::parse($event->starts_at);
            $end = Carbon::parse($event->ends_at);

            // Clip events that span midnight to this date
            $ranges[] = [
                'start' => $start->lt($dayStart) ? '00:00' : $start->format('H:i'),
                'end' => $end->gt($dayEnd) ? '23:59' : $end->format('H:i'),
            ];
        }

        return $ranges;
    }
}
