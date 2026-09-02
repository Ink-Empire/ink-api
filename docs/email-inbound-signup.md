# Provisional Artist Signup

An artist's images can reach us before the artist does. There are two ways in,
and both produce the same thing: a claimable artist account with the images
waiting in a review queue.

| Entry point | Who starts it | `BulkUpload.source` |
|---|---|---|
| Setup mailbox | The artist emails `setup@getinked.in` | `email` |
| Admin panel | An admin enters an artist who sent work in some other way | `admin` |

Both call `ArtistOnboardingService`, so the account rules below — temp
password, auto-verification, forced password reset — are identical whichever
route was taken. The service exists precisely so the two cannot drift apart.

The one deliberate difference is expiry: see
[Provisional account expiry](#provisional-account-expiry).

## The setup mailbox

Artists email images to `setup@getinked.in` to add work to their portfolio. If no account exists for the sender address, one is created automatically.

### How it works

1. The scheduler runs `email:fetch-inbound` every 3 minutes (`Kernel.php`).
2. The command polls `setup@getinked.in` via IMAP for unseen messages.
3. For each message with image attachments, it finds or creates the artist's account.
4. Images are saved through the existing `BulkUpload` pipeline (`source = 'email'`).
5. The artist receives one email: a receipt with credentials (new accounts) or a review link (existing accounts).

## The admin panel

For artists who send their work directly to a person rather than to the
mailbox. **Admin → Onboard Artist** takes their email, name, images, and
optionally a location and a studio.

1. `POST /api/admin/artists/onboard` (admin only).
2. The same `ArtistOnboardingService` finds or creates the artist and stores
   the images as a `BulkUpload` with `source = 'admin'`.
3. The artist gets the same receipt email with their temp password.

Two fields are optional and behave carefully:

- **Location** is captured through the Places picker so coordinates come with
  it. A location without coordinates is discarded rather than saved, because
  proximity search matches on the coordinates and `users:backfill-timezones`
  derives the timezone from them — an artist with neither is unsearchable and
  syncs bookings to Google in UTC. An existing location is never overwritten.
- **Studio** attaches the artist as an unverified join request recorded as
  `initiated_by = 'artist'`, matching registration, so it lands in the studio
  owner's existing queue. A studio that is not on the platform yet can be
  created from the same screen; it stays unverified and ownerless until
  somebody claims it.

## New account creation

When the sender has no existing account:

- A provisional artist account is created (`type_id = UserTypes::ARTIST_TYPE_ID`).
- `email_verified_at` is set immediately — the sender proved ownership by emailing from that address, so no verification step is needed.
- A human-readable temp password is generated (format: `XXXX-XXXX-0000`) and hashed into the DB. The plaintext version is included in the receipt email only — it is never stored.
- `force_password_reset = true` — the frontend should redirect to a password change screen on first login and clear this flag on success.
- `has_accepted_toc = false` — the artist will see the Terms & Conditions prompt on first login.
- The `Registered` event fires, but because `email_verified_at` is already set, `SendEmailVerificationNotification` skips sending a verification email. No duplicate emails.
- A Slack notification fires to `SLACK_NEW_CONTACT_WEBHOOK_URL` with the artist's name, email, photo count, and expiry date.

### First login experience

1. Artist uses credentials from the receipt email to log in.
2. ToC acceptance prompt appears (standard flow).
3. App detects `force_password_reset = true` and prompts password change. Clear the flag on save.
4. The `BulkUpload` from the email is waiting under **Dashboard → Uploads** to review and publish.

### Provisional account expiry

If the artist never logs in, `users:expire-provisional` runs nightly at 02:00 and deletes:
- The user account
- All associated `BulkUpload` and `BulkUploadItem` records
- The `InboundEmailLog` record

Criteria for expiry: `force_password_reset = true` AND `last_login_at IS NULL` AND `created_at < 14 days ago` AND email exists in `inbound_email_logs`.

**Mailbox accounts expire. Admin-created accounts do not.** That last
condition is what separates them: only the mailbox path writes an
`InboundEmailLog` row.

The reasoning is that a mailbox account is created by a stranger emailing in,
so one still unclaimed after fourteen days is abandoned and worth clearing. An
admin-built page is one a person made on purpose for an artist they are
onboarding, possibly well before that artist is ready to log in, and deleting
it would destroy real work.

The trade-off is that abandoned admin-created pages accumulate with no
automatic cleanup. `ExpireProvisionalAccountsTest` pins both halves of this so
the difference stays a decision rather than drifting.

```bash
# Dry-run to preview what would be expired
php artisan users:expire-provisional --dry-run

# Live run (done automatically at 02:00 daily)
php artisan users:expire-provisional
```

## Processing log

Every found message (even dry-runs are excluded) is recorded in `inbound_email_logs` before processing begins:

| Column | Purpose |
|---|---|
| `message_uid` | IMAP UID — prevents double-processing the same message |
| `sender_email` / `sender_name` | Who sent it |
| `image_count` | Attachments found |
| `is_processed` | `false` until fully complete |
| `processed_at` | Timestamp on success |
| `error_message` | Exception text on failure |
| `bulk_upload_id` | FK to the resulting `BulkUpload` |

If processing fails, `error_message` is set and the IMAP message is still marked seen (to avoid an infinite retry loop). Fix the underlying issue, clear the log row, and mark the message unseen to requeue it:

```bash
# Clear the failed log row
php artisan tinker --execute="App\Models\InboundEmailLog::where('is_processed', false)->delete();"

# Mark the IMAP message unseen (replace 2 with the message sequence number)
php -r "
  \$c = imap_open('{imap.porkbun.com:993/imap/ssl/novalidate-cert}INBOX', 'setup@getinked.in', 'PASSWORD', 0, 1);
  imap_clearflag_full(\$c, '2', '\\\\Seen');
  imap_close(\$c);
"
```

## Running the command

```bash
# Dry run — connects to IMAP, logs what it would do, no DB writes
php artisan email:fetch-inbound --dry-run

# Live run
php artisan email:fetch-inbound
```

The scheduler entry in `Kernel.php`:
```php
$schedule->command('email:fetch-inbound')
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

## Key files

| File | Role |
|---|---|
| `app/Services/ArtistOnboardingService.php` | Account creation, image ingestion, location and studio rules — shared by both entry points |
| `app/Console/Commands/FetchInboundEmails.php` | IMAP polling; delegates to the service |
| `app/Http/Controllers/Admin/ArtistOnboardingController.php` | `POST /admin/artists/onboard`; delegates to the service |
| `app/Console/Commands/ExpireProvisionalAccounts.php` | Nightly cleanup of unclaimed mailbox accounts |
| `inked-in-www/nextjs/admin/pages/OnboardArtistPanel.tsx` | The admin screen |
| `app/Models/InboundEmailLog.php` | Processing audit log |
| `app/Notifications/InboundEmailReceiptNotification.php` | Receipt email (new + existing accounts) |
| `resources/views/mail/inbound-email-receipt.blade.php` | Email template |
| `database/migrations/2026_06_29_000001_create_inbound_email_logs_table.php` | Log table migration |
| `config/services.php` | IMAP connection config (`inbound_imap` key) |

## Environment variables

```
INBOUND_IMAP_HOST=imap.porkbun.com
INBOUND_IMAP_PORT=993
INBOUND_IMAP_USERNAME=setup@getinked.in
INBOUND_IMAP_PASSWORD=
INBOUND_IMAP_ENCRYPTION=ssl
```

## Compared to standard artist signup

| | Standard signup | Email inbound |
|---|---|---|
| Email verified | User clicks link | Auto-verified (sent from that address) |
| Password | User sets it | Temp password emailed, user changes later |
| ToC accepted | During signup | Prompted on first login |
| Profile complete | Name, bio, styles collected | Minimal — name derived from email, rest filled in later |
| Images | Uploaded via dashboard | Attached to the inbound email |
