# Tattoo Beacon System

The Tattoo Beacon (also called "Looking for a Tattoo" or "Let Artists Find You") allows clients to broadcast that they're looking for a tattoo, notifying nearby artists who can then reach out with quotes.

## Overview

When a client enables their beacon:
1. They provide details about what they're looking for (timing, description, style preferences)
2. Nearby artists are automatically notified via email
3. The client can see how many artists were notified
4. Artists can view the lead and contact the client

## Database

### `tattoo_leads` table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | bigint | The client who created the lead |
| `timing` | string | When they want the tattoo: `week`, `month`, `year`, or null |
| `interested_by` | date | Calculated deadline based on timing |
| `allow_artist_contact` | boolean | Whether artists can contact them |
| `style_ids` | json | Array of style IDs they're interested in |
| `tag_ids` | json | Array of tag IDs for themes |
| `custom_themes` | json | Array of custom theme strings |
| `description` | string | Free-text description of what they want |
| `is_active` | boolean | Whether the beacon is currently active |
| `lat` | decimal(10,7), nullable | Latitude for geo matching |
| `lng` | decimal(10,7), nullable | Longitude for geo matching |
| `location` | string, nullable | Human-readable location string |
| `location_lat_long` | string, nullable | Combined `"lat,lng"` representation |
| `radius` | integer, default `50` | Search radius for nearby artists |
| `radius_unit` | string(10), default `mi` | `mi` or `km` |

## API Endpoints

### Get Lead Status
```
GET /api/leads/status
```

Returns the current user's active lead status and notification count.

**Response:**
```json
{
  "has_lead": true,
  "is_active": true,
  "artists_notified": 12,
  "lead": {
    "id": 1,
    "timing": "month",
    "interested_by": "2026-02-21",
    "allow_artist_contact": true,
    "style_ids": [1, 3],
    "tag_ids": [5, 8],
    "custom_themes": ["floral", "nature"],
    "description": "Looking for a botanical sleeve...",
    "is_active": true
  }
}
```

### Create Lead
```
POST /api/leads
```

Creates a new lead and notifies nearby artists.

**Request:**
```json
{
  "timing": "month",
  "allow_artist_contact": true,
  "style_ids": [1, 3],
  "tag_ids": [5, 8],
  "custom_themes": ["floral"],
  "description": "Looking for a botanical piece..."
}
```

### Toggle Lead
```
POST /api/leads/toggle
```

Toggles the active status of the user's lead. If no lead exists, creates a minimal one.

### Update Lead
```
PUT /api/leads
```

Updates specific fields of the active lead.

### Get Leads for Artists
```
GET /api/leads/artists
```

Returns active, contactable leads for artists to browse.

## Notification Flow

### When a Lead is Created

1. Client submits beacon form with `allow_artist_contact: true`
2. `TattooLeadController::store()` creates the lead
3. `NotifyNearbyArtistsOfBeacon` job is dispatched
4. Job finds artists matching client's location
5. `TattooBeaconNotification` sent to each artist
6. Notifications logged via `spatie/laravel-notification-log`

### Artist Matching

`NotifyNearbyArtistsOfBeacon::findNearbyArtists()` resolves nearby artists in two ways:

**1. Geo path (preferred — used when the lead has `lat` + `lng`):**
- Delegates to `ArtistService::getNearby($lat, $lng, "{$radius}{$unit}")`
- That service calls `Artist::search()->whereDistance('location_lat_long', $lat, $lng, $distance)` against the artists Elasticsearch index, where `location_lat_long` is mapped as a `geo_point`
- Eloquent `Artist` models are then rehydrated from the matching IDs via `Artist::whereIn('id', $ids)->get()` so the rest of the job has full models for `->notify()`
- Distance is built from `$lead->radius` + `$lead->radius_unit` so both `mi` and `km` are passed natively to Elasticsearch — no manual unit conversion
- `ArtistService::getNearby()` accepts a `$distance` parameter (default `'50mi'`) and is reusable from anywhere else that needs nearby artists

**2. String-location fallback (used only when the lead has no coordinates):**
- Exact match on `location` field
- Partial match on city name (e.g., "Los Angeles" matches "Los Angeles, CA")
- Hits MySQL directly via `User::where('type_id', UserTypes::ARTIST_TYPE_ID)`

Both paths cap results at 50 artists per notification batch.

Future enhancements could include:
- Style matching based on `style_ids`
- Artist availability/books open status
- Pushing the string-location fallback through Elasticsearch as well

## Notification Tracking

All beacon notifications are tracked using `spatie/laravel-notification-log`.

### Logged Data

Each notification logs:
```php
[
    'event_type' => 'beacon_request',
    'sender_id' => $client->id,        // The client who created the beacon
    'sender_type' => User::class,
    'reference_id' => $lead->id,       // The TattooLead
    'reference_type' => TattooLead::class,
]
```

### Querying Notification Stats

```php
use Spatie\NotificationLog\Models\NotificationLogItem;

// Count artists notified for a specific lead
$count = NotificationLogItem::query()
    ->whereJsonContains('extra->event_type', 'beacon_request')
    ->whereJsonContains('extra->reference_id', $leadId)
    ->count();
```

## Files

### Models
- `App\Models\TattooLead` - The lead/beacon model

### Controllers
- `App\Http\Controllers\TattooLeadController` - All lead endpoints

### Jobs
- `App\Jobs\NotifyNearbyArtistsOfBeacon` - Finds and notifies nearby artists

### Services
- `App\Services\ArtistService::getNearby()` - Reusable Elasticsearch geo lookup that returns Eloquent `Artist` models within a given radius of a coordinate

### Notifications
- `App\Notifications\TattooBeaconNotification` - Email sent to artists

### Views
- `resources/views/mail/tattoo-beacon.blade.php` - Email template

## Frontend

The beacon UI is in `ClientDashboardContent.tsx`:
- Toggle switch to enable/disable beacon
- Shows "X artists in your area notified" when active
- Opens `TattooIntent` modal to collect preferences when enabling

## Seeking Posts (Tattoo + Beacon Link)

Clients can create "seeking" posts — tattoo uploads with `post_type = 'seeking'` that include reference images of work they want done. When a seeking post is created:

1. A `TattooLead` record is automatically created with the post's styles/tags/description
2. The tattoo is linked to the lead via `tattoo_lead_id` FK
3. `NotifyNearbyArtistsOfBeacon` is dispatched to notify nearby artists
4. The post appears in the main feed with a teal "Seeking Artist" strip
5. Users can filter the feed by `post_type=seeking` to browse seeking posts

### How it works in the API

When `POST /tattoos/create` is called with `post_type: 'seeking'`:
- `TattooService::createTattoo()` calls `createSeekingLead()` internally
- The lead is created with `allow_artist_contact` (default true) and optional `timing`
- The tattoo gets `approval_status = 'user_only'`, `is_visible = true`
- No separate `/leads` API call is needed from the frontend

### Database

The `tattoos` table now includes:
- `post_type` (string, default 'portfolio') — values: `portfolio`, `flash`, `seeking`
- `flash_price` (decimal, nullable) — for flash designs
- `flash_size` (string, nullable) — for flash designs
- `tattoo_lead_id` (FK to `tattoo_leads`, nullable) — links seeking posts to their beacon

## Future Considerations

1. **Style matching** - Only notify artists whose styles match the client's preferences
2. **Rate limiting** - Prevent spam by limiting how often a user can create new leads
3. **Lead expiration** - Automatically deactivate leads past their `interested_by` date
4. **Flash availability** - Add `is_available` boolean to mark flash designs as taken
