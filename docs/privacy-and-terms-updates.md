# Privacy Policy and Terms Updates

Draft wording for the gaps between what the live documents say and what the
code actually does. Written against the codebase, not from a template, so the
claims can be checked against the sources listed at the bottom.

Not legal advice. The terms changes in particular sit next to liability,
indemnification and dispute resolution clauses and should be reviewed by
someone qualified before shipping.

Target files:

- `inked-in-www/nextjs/pages/privacy.tsx`
- `inked-in-www/nextjs/pages/terms-of-service.tsx`

---

## Privacy policy

### Section 01, Information We Collect

Add calendar data. Nothing in the current section covers it.

> **Calendar information.** If you choose to connect a Google Calendar, we
> collect the start time, end time, all day flag, status and title of events on
> that calendar, along with the email address of the connected Google account.
> We use this only to work out when you are unavailable and to add your InkedIn
> bookings to your calendar. Connecting a calendar is optional and you can
> disconnect at any time.

### Section 02, How We Use Your Information

One existing sentence needs attention once OpenAI is disclosed:

> We do not use your data for automated decision-making or profiling that
> produces legal effects.

That remains true, since tag suggestions produce no legal effect. But it sits
awkwardly beside a newly disclosed AI image analysis step and invites the
question. Suggested replacement:

> We use automated analysis to suggest descriptive tags for uploaded images, so
> that work can be found in search. These suggestions are shown to you and do
> not make decisions about you. We do not use your data for automated
> decision-making or profiling that produces legal effects.

### Section 03, Third-Party Services

The list currently names three providers. At least eight process user data.
Replace the array in the page with the full set.

| Provider | Purpose |
|---|---|
| Firebase | Push notifications and analytics. May collect device identifiers and app usage data. Governed by Google's privacy policy. |
| Elastic Cloud | Powers search. Processes artist profiles, portfolio metadata and search queries to deliver relevant results. |
| Resend | Transactional email delivery. Processes email addresses to send account related communications such as verification and notifications. |
| Amazon Web Services | Stores uploaded images and files. Data is held in the United States. |
| imgix | Image delivery. Uploaded images are served and resized through imgix's content delivery network. |
| OpenAI | Suggests descriptive tags for uploaded tattoo images. We send a link to the image, which OpenAI retrieves and analyses. Under OpenAI's API terms, content sent through the API is not used to train their models. |
| Google | Calendar sync when you connect a calendar, sign in and profile information for that connection, and address lookup when a studio location is added. |
| Sentry | Error monitoring. Receives technical diagnostics when something fails, so we can fix it. Configured not to send personal information by default. |

Confirm the OpenAI training sentence against their current API terms before
publishing. It is accurate as written but it is their policy, not ours, and it
has changed before.

### New section, Google Calendar Data

Google's OAuth verification checks for this specifically, including the final
sentence, which it looks for close to verbatim. Add it as its own numbered
section.

> **Connecting your Google Calendar.** Connecting a Google Calendar is
> optional. InkedIn works without it. You can disconnect at any time from your
> calendar page, and you can revoke access directly from your Google Account
> permissions page.
>
> **What we access.** We read the times you are already busy, so the platform
> does not offer a booking slot when you are unavailable. We create and update
> events on your calendar for appointments booked through InkedIn. We read your
> email address and basic profile so you can see which Google account is
> connected.
>
> **What we store.** The times, all day flag and status of your events, so we
> can block the matching slots. The title of those events, shown only to you in
> your own InkedIn calendar and never to clients or other artists. Access and
> refresh tokens, encrypted at rest. The email address of the connected
> account. We do not store the descriptions, locations or attendees of your
> calendar events.
>
> **What we do not do.** We do not show your personal event details to clients
> or other artists. Clients see only that a time is unavailable. We do not sell
> or transfer your Google data, use it for advertising, or use it to train
> machine learning or artificial intelligence models. We do not allow humans to
> read it, except where you have given explicit permission for a support issue,
> where it is necessary for security purposes such as investigating abuse, or
> where we are required to by law.
>
> **Removing it.** Disconnecting from your calendar page immediately deletes the
> stored events and tokens for that connection. Deleting your InkedIn account
> removes them along with it.
>
> InkedIn's use and transfer of information received from Google APIs to any
> other app adheres to the Google API Services User Data Policy, including the
> Limited Use requirements.

---

## Terms of service

### New section, Connected Accounts

The terms cover bookings in section 09 but say nothing about connecting an
outside account. Suggested placement is after section 09.

> **Connecting third-party accounts.** You may choose to connect a third-party
> account, such as a Google Calendar, to your InkedIn account. Connecting is
> optional and you can disconnect at any time.
>
> You are responsible for the third-party account you connect, including keeping
> access to it and complying with that provider's own terms. Your use of the
> connected service remains governed by that provider's terms and privacy
> policy.
>
> When you connect a calendar, you authorise InkedIn to read your existing
> commitments so we can avoid offering times you are unavailable, and to add,
> update and remove InkedIn bookings on that calendar. We describe exactly what
> we read and store in our Privacy Policy.
>
> Calendar synchronisation depends on a service we do not control. It may be
> delayed, interrupted, or stop working if the provider changes their service or
> if your authorisation expires or is revoked. We will tell you by email if your
> connection stops working and needs reconnecting. You remain responsible for
> confirming your own availability, and we are not liable for a booking conflict
> arising from a synchronisation delay or failure.
>
> Disconnecting removes the stored calendar data held for that connection. It
> does not cancel bookings already made, and it does not remove events already
> written to your calendar.

The clause about not being liable for sync-related booking conflicts is the one
most worth a lawyer's eye, because it allocates risk for a failure mode the
platform is closer to than the artist is.

---

## United States user rights

Additive, not a replacement. The New Zealand framing stays while the entity is
still registered there. This closes a gap that exists today regardless of the
move: the terms already carry section 19 for United States users, so there are
United States users, and the privacy policy currently tells all of them their
only complaint route is the New Zealand Privacy Commissioner. That is not a
route a resident of California can actually use.

Add as its own numbered section, after the existing Your Rights section.

> **If you are in the United States.** Depending on where you live, you may have
> additional rights under your state's privacy law. Where those rights apply, you
> can ask us to tell you what personal information we hold about you and how we
> use it, ask us to correct it, ask us to delete it, and receive a copy of it.
> You will not be treated differently for exercising any of these rights.
>
> We do not sell your personal information, and we do not share it for
> cross-context behavioural advertising.
>
> To make a request, contact us at the address in the Contact section. We may
> need to verify your identity before we act on it, so that nobody else can make
> a request about you. You may also authorise someone to make a request on your
> behalf.
>
> **California residents.** The California Consumer Privacy Act gives you the
> rights described above, along with the right to limit our use of sensitive
> personal information. If you believe we have not handled your request
> properly, you can contact the California Attorney General.

### Two things to settle before this ships

**The sale and sharing sentence needs checking against Firebase.** Under the
CCPA, "sharing" specifically means disclosing personal information for
cross-context behavioural advertising, and the definition is broader than most
people expect. The privacy policy already describes Firebase as collecting
"device identifiers and app usage data" for analytics. Whether that
configuration amounts to sharing depends on which Firebase products are enabled
and whether the data flows into Google's advertising products. Confirm it before
stating a flat no, because that sentence is one of the most commonly enforced in
the whole regime.

**Sensitive personal information.** The California paragraph mentions limiting
its use. Worth confirming whether anything collected qualifies. Uploaded tattoo
images can reveal information some frameworks treat as sensitive, and images are
sent to OpenAI for tagging.

---

## Jurisdiction migration checklist

For when the entity move to the United States completes. Nothing here should
change before then, because a governing law clause that does not match the
contracting entity is weaker than one pointing at a jurisdiction you have left.

Work through in this order.

1. **Decide the state.** Everything below depends on it, and it is a business
   and tax decision before it is a drafting one.
2. **Privacy line 131.** Replace "a company registered in New Zealand" with the
   new registration. Do not change this until the registration is real.
3. **Privacy lines 136, 270 and terms line 334.** Replace New Zealand Privacy
   Act 2020 compliance with the applicable state law. Line 270 covers
   international transfers and its reasoning inverts once you are United States
   based, since the servers are already there.
4. **Privacy line 232.** Make the United States rights section primary and
   demote the New Zealand section to a "if you are in New Zealand" block, or
   remove it if you no longer have New Zealand users.
5. **Privacy line 243.** Replace the New Zealand Privacy Commissioner with the
   state Attorney General as the complaint route.
6. **Terms line 360.** Change the liability cap from NZD 100 to a USD figure.
   Worth revisiting the amount rather than converting it.
7. **Terms line 361.** Replace the Consumer Guarantees Act 1993 carve-out with
   the equivalent state consumer protection law.
8. **Terms line 403.** Change governing law and exclusive jurisdiction to the
   chosen state. This is the clause with the most consequence and the one most
   worth a lawyer's time.
9. **Terms section 19.** Once United States law governs throughout, "Additional
   Terms for Users in the United States" is no longer an addendum. Fold it into
   the main terms rather than leaving it reading as an exception.
10. **Both effective dates.** Move them, and decide whether a change of
    governing law warrants notice to existing users beyond the standard
    posting. Changing which courts hear a dispute is the kind of change users
    are usually told about directly.

---

## Sources

Every claim above traces to code, so a reviewer can verify rather than trust.

| Claim | Source |
|---|---|
| Event times, title, status and all day are stored | `app/Models/ExternalCalendarEvent.php` fillable |
| Descriptions and locations are not stored | `2026_09_01_000001_drop_metadata_from_external_calendar_events_table` |
| Event titles are shown only to the owner | `CalendarOAuthController::getEvents()` is authenticated and scoped to the requesting user |
| Tokens are encrypted at rest | `CalendarConnection::setAccessTokenAttribute()` and `setRefreshTokenAttribute()` |
| Disconnecting deletes events and tokens | `CalendarOAuthController::disconnect()` |
| Artists are emailed when a connection dies | `CalendarDisconnectedNotification`, sent from `GoogleCalendarService::refreshToken()` |
| Scopes requested | `GoogleCalendarService::__construct()` |
| Image links are sent to OpenAI | `TagService`, three `image_url` payloads to `gpt-4o-mini` |
| Google Places is in use | `config/services.php`, `places_api_key` |
| Images are stored on S3 and served via imgix | `config/filesystems.php` |
| Sentry does not send PII by default | `config/sentry.php`, `send_default_pii` defaults to false |

---

## Before publishing

The privacy policy must be reachable at a public URL without signing in, and
linked from the homepage and the OAuth consent screen entry, or Google
verification will not pass. See `docs/google-calendar-rollout.md`.

Update the Effective Date on both pages. The privacy policy currently reads
February 17, 2026 at line 109, and section 10 tells people that date is how they
know the policy changed. Adding five processors and a calendar section without
moving it would break a promise the document makes about itself.

Section 10 also says material changes are notified through the platform or by
email. Adding previously undisclosed processors, including sending uploaded
images to OpenAI, is the kind of change that reads as material. Worth deciding
whether a notice goes out rather than letting the change land silently.
