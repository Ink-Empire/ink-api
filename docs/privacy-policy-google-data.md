# Privacy Policy: Google User Data

Draft content for the Google API Services sections of the InkedIn privacy
policy. OAuth verification checks that a public privacy policy discloses what
Google user data the app accesses, what it does with it, and how a person
removes it.

This is a draft grounded in what the code actually does today, not legal
advice. Have it reviewed before publishing, and keep it accurate if the data
handling changes.

Publish it at a stable public URL and link it from the app homepage and the
OAuth consent screen. Google checks that the link resolves.

---

## Accuracy note

The draft below describes the app **after** the recommended change in
`docs/google-calendar-rollout.md` to stop storing event descriptions and
locations. If that change has not shipped, the second bullet under What We
Store is wrong, and the policy must instead disclose that descriptions and
locations are stored. Do not publish a policy that understates what is
collected.

---

## Suggested sections

### Connecting Your Google Calendar

Connecting a Google Calendar is optional. InkedIn works without it. You can
disconnect at any time from your calendar page, and you can revoke access
directly from your Google Account permissions page.

### What We Access

When you connect a Google Calendar, you grant InkedIn these permissions:

- **See your calendar events.** We read the times you are already busy so the
  platform does not offer a booking slot when you are unavailable.
- **Create and change events on your calendar.** We add appointments booked
  through InkedIn to your calendar, and update or remove them when a booking
  changes or is cancelled.
- **Your email address and basic profile.** We use these to show which Google
  account is connected, so you can tell which calendar is in use.

### What We Store

- The times, all day flag, and status of events on your connected calendar, so
  we can block the corresponding slots.
- The title of those events, shown only to you in your own InkedIn calendar so
  you can recognise your own commitments. It is never shown to clients or to
  other artists.
- Access and refresh tokens for your Google account, encrypted at rest, so sync
  can continue without asking you to sign in repeatedly.
- The email address of the connected Google account.

We do not store the descriptions, locations, or attendees of your calendar
events.

### What We Do Not Do

- We do not show your personal calendar event details to clients or other
  artists. Clients see only that a time is unavailable.
- We do not sell or transfer your Google data.
- We do not use your Google data for advertising.
- We do not use your Google data to train machine learning or artificial
  intelligence models.
- We do not allow humans to read your Google data, except where you have given
  explicit permission for a support issue, where it is necessary for security
  purposes such as investigating abuse, or where we are required to by law.

### Removing Your Data

Disconnecting your calendar from your InkedIn calendar page immediately deletes
the stored events and the stored tokens for that connection. You can also revoke
InkedIn's access from your Google Account permissions page at any time, which
stops all further sync.

Deleting your InkedIn account removes the connection and its stored calendar
data along with it.

### Limited Use Disclosure

InkedIn's use and transfer of information received from Google APIs to any other
app adheres to the Google API Services User Data Policy, including the Limited
Use requirements.

---

## Checklist before submitting for verification

- The policy is at a public URL that does not require sign in.
- It is linked from the app homepage and from the OAuth consent screen entry.
- The Limited Use sentence appears verbatim. Google looks for it.
- Every scope requested on the consent screen is described in plain language.
- The described handling matches what the code actually does. A reviewer testing
  the app and seeing behaviour the policy does not cover is a common rejection.
