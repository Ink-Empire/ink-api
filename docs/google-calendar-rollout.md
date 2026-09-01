# Google Calendar Public Rollout

Everything needed to take calendar sync from a single test connection to real
artists. The application work is mostly done. The gating work is in Google Cloud
Console and only an owner of that project can do it.

---

## The gate: OAuth consent screen

`calendar.readonly` and `calendar.events` are **sensitive scopes**. Until the
OAuth client is published and verified, arbitrary users cannot connect.

Check the current state at **Cloud Console, APIs and Services, OAuth consent
screen, Publishing status**.

| Status | What a real artist gets |
|---|---|
| Testing | Only explicitly listed test users, capped at 100. Refresh tokens expire after seven days. |
| In production, unverified | An unverified app warning screen, and a cap on new grants. |
| In production, verified | Everyone, no warning. |

The seven day refresh token expiry in Testing mode is worth knowing about,
because it looks exactly like a revoked grant. Connection 1 in production last
refreshed on 2026-01-09 and then failed with `invalid_grant` forever after,
which is the shape that expiry leaves behind.

Calendar scopes are sensitive rather than restricted, so verification does not
require the annual third party security assessment that Gmail and Drive scopes
do.

### Verification checklist

1. Verify ownership of `getinked.in` in Google Search Console, under the same
   Google account that owns the Cloud project.
2. Give the app a homepage that explains what it does and links the privacy
   policy.
3. Publish a privacy policy at a stable public URL. See
   `docs/privacy-policy-google-data.md` for the sections Google requires.
4. Record a demo video showing the consent screen, the connection flow, and
   where each scope is actually used in the product.
5. Write a justification for each scope. `calendar.readonly` reads busy time so
   the platform does not offer a slot the artist is not free for.
   `calendar.events` writes confirmed bookings onto their calendar.
6. Submit and expect revisions. Days to several weeks is normal.

Google changes these requirements. Treat this list as a starting point and
confirm against the console.

### Webhook domain verification

Google push notifications require the receiving domain to be verified in the
Cloud project, separately from Search Console ownership.
`GoogleCalendarService::setupWebhook()` registers `config('app.url')` plus
`/api/webhooks/google-calendar` as the channel address. If that domain is not
verified in the project, `events->watch()` is rejected and sync silently falls
back to the six hourly poll.

Webhook setup only runs when `config('app.env') === 'production'`, so staging
never exercises this path.

---

## Audit findings

### Data being stored that nothing uses

`GoogleCalendarService::syncSingleEvent()` stores a `metadata` payload on every
external event containing `location`, `description` and `html_link`, taken from
the artist's personal calendar. Nothing in the application reads that column.
It is written and never used.

This matters twice over. It is the kind of over collection that draws pushback
during OAuth verification, where reviewers expect data collection limited to
what the user facing feature needs. And it means a database breach exposes the
descriptions and locations of every private appointment on every connected
artist's calendar, for no product benefit.

`title` is used. It is shown to the artist in their own calendar view through
`CalendarOAuthController::getEvents()`. Times and status drive availability.
Those all earn their place. The `metadata` column does not.

Recommended before rollout: stop writing `metadata`, and clear the existing
column.

### Exposure check

External event titles are not publicly exposed. `getEvents()` is authenticated
and scoped to the requesting user's own connection, and the public
`getArtistAppointments()` endpoint returns appointments rather than external
events. Only the artist sees their own event titles.

### Webhook renewal at scale

`RefreshCalendarWebhooks` previously loaded every expiring connection and
renewed each one inline. Each renewal is two or three sequential Google calls,
so the job's runtime grew linearly with the number of artists, and because it
retries three times, a failure near the end re-created channels it had already
renewed. It now chunks and dispatches one `RefreshCalendarWebhook` per
connection, so a retry redoes a single calendar.

### Sync fan out

`sync-due-calendars` in `app/Console/Kernel.php` uses `->each()`, which
paginates with offsets. Dispatched jobs update `last_synced_at` asynchronously,
so rows can leave the result set between pages and a connection can be skipped.
The next hourly run picks it up, so the impact is a delayed sync rather than a
lost one. Worth moving to `chunkById` if the connection count grows.

### Failure isolation

Good. `SyncUserCalendar` carries `WithoutOverlapping` keyed per connection, so
one artist's calendar cannot block another's, and a permanently broken
connection is taken out of rotation rather than failing hourly forever.

### Quota

Google Calendar API default quota is far above what this uses. Each connection
polls at most four times a day plus webhook triggered syncs. Per user rate
limits apply per Google account, so they do not aggregate across artists. Not a
constraint at any realistic near term scale.

---

## Before opening it up

Test with a second real Google account that is not the demo account and not a
listed test user. That is the only way to see what a real artist sees, including
the unverified app warning if verification has not completed.

Walk the whole path. Connect, confirm busy time blocks a slot, take a booking
and confirm it appears on the Google calendar, decline consent once to confirm
the cancel path returns to `/calendar` with a readable message, then disconnect
and confirm the events are removed.

---

## After launch

`google:keepalive` runs weekly and posts to the ops channel on failure. With
`GOOGLE_KEEPALIVE_CONNECTION_ID` pinned to an InkedIn owned account, it never
touches an artist's connection.

Watch `failed_jobs` for `SyncUserCalendar`. A transient Google failure now
retries and leaves the connection untouched, so a spike there means Google is
unavailable rather than that artists are being disconnected.

Watch for `CalendarDisconnectedNotification` volume. A cluster of them at once
means something systemic, not a set of individual revocations. If the OAuth
client is in Testing mode, expect one per artist every seven days.
