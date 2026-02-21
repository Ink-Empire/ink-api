# Booking Flow

## Overview

The booking flow allows clients to request consultations or appointments with artists. The flow spans three platforms (React Native, NextJS, Laravel API) and involves calendar availability, real-time messaging, push notifications, and Google Calendar sync.

## Client Booking Request

### Step 1: Select Date and Booking Type

The client navigates to the artist's calendar and taps an available date.

- **CalendarDayModal** opens as a bottom sheet showing the selected date
- If the artist accepts both consultations and appointments, a **toggle** lets the client choose
- If the artist only accepts one type, an info message is shown: "This artist only accepts consultations/appointments"
- Deposit amount is displayed when appointment type is selected
- Client taps "Request Booking" / "Request Consultation" to proceed

### Step 2: Select Time Slot

**BookingFormModal** opens as a centered modal (keyboard-aware) with the pre-selected booking type.

- Available time slots are fetched from the API based on date and booking type
- Deposit info banner is shown for appointments
- Client selects a time slot, optionally adds notes, and submits

### Step 3: API Creates Appointment

```mermaid
sequenceDiagram
    participant C as Client (NextJS/RN)
    participant API as ink-api
    participant DB as Database
    participant N as Notifications

    C->>API: GET /artists/{id}/available-slots?date=...&type=...
    API->>DB: Query artist_availability (day_of_week)
    API->>DB: Query artist_settings (consultation_duration, deposit_amount)
    API->>DB: Query appointments (date, pending/booked)
    API-->>C: { slots, working_hours, consultation_window, consultation_duration, deposit_amount }

    C->>API: POST /appointments/create
    API->>DB: Validate artist exists, not blocked
    API->>DB: Create appointment (status=pending)
    API->>DB: Find/create conversation (linked via appointment_id)
    API->>DB: Create booking_card message (metadata includes status=pending, deposit, duration)
    API->>N: Send BookingRequestNotification (email + FCM push to artist)
    API->>N: Sync to Google Calendar (if connected, as tentative)
    API-->>C: { conversation_id } -> redirect to inbox
```

### Step 4: Client Redirected to Inbox

After successful creation, the client is redirected to the conversation with the artist. The **booking_card** message displays:
- "Booking Request" header with **PENDING** badge
- Date, time, duration, deposit details
- The client's notes as italic text below

## Available Slots Logic

```mermaid
flowchart TD
    A([Start]) --> B{Day off?}
    B -->|Yes| C([Return empty slots])
    B -->|No| D{Type?}

    D -->|Consultation| E{Has consultation window?}
    E -->|Yes| F[Generate slots within window]
    E -->|No| G[Generate slots within full working hours]
    F --> H[Step = consultation_duration]
    G --> H

    D -->|Appointment| I{Has consultation window?}
    I -->|Yes| J[Generate slots outside window]
    I -->|No| K[Generate slots within full working hours]
    J --> L[Step = 30 min]
    K --> L

    H --> M[Filter overlapping appointments]
    L --> M
    M --> N([Return available slots])
```

## Artist Response Flow

The artist sees each booking request as a **booking_card** in their inbox conversation. Pending cards include **Accept** and **Decline** buttons directly on the card (no separate banner).

```mermaid
sequenceDiagram
    participant A as Artist
    participant API as ink-api
    participant DB as Database
    participant N as Notifications

    A->>API: POST /appointments/{id}/respond { action: accept/decline }

    alt Accept
        API->>DB: Update appointment status to "booked"
        API->>DB: Update booking_card message metadata (status=accepted)
        API->>DB: Create system message ("Booking request accepted...")
        API->>N: Send BookingAcceptedNotification (email + FCM push to client)
        API->>N: Sync to Google Calendar (update event)
    else Decline
        API->>DB: Update appointment status to "cancelled"
        API->>DB: Update booking_card message metadata (status=declined)
        API->>DB: Create system message ("Booking request declined...")
        API->>N: Send BookingDeclinedNotification (email + FCM push to client)
        API->>N: Remove from Google Calendar
    end

    API-->>A: AppointmentResource
    Note over A: Messages refetch, card updates to CONFIRMED/DECLINED,<br/>system message appears immediately
```

## Booking Card Message

The `booking_card` message type is stored in the `messages` table with:

| Field | Description |
|-------|-------------|
| `type` | `'booking_card'` |
| `appointment_id` | Links to the appointment record |
| `metadata.appointment_id` | Appointment ID (for frontend reference) |
| `metadata.type` | `'tattoo'` or `'consultation'` |
| `metadata.status` | `'pending'` -> `'accepted'` or `'declined'` |
| `metadata.date` | Formatted date string |
| `metadata.time` | Formatted time range |
| `metadata.duration` | Duration string |
| `metadata.deposit` | Formatted deposit amount (e.g., "$666") or null |
| `content` | Client's message/notes |

The `status` field in metadata is updated by the `respondToRequest` endpoint when the artist accepts or declines. The frontend renders different states:

- **pending**: Shows PENDING badge + Accept/Decline buttons (artist view only)
- **accepted**: Shows CONFIRMED badge, no action buttons
- **declined**: Shows DECLINED badge, no action buttons

## Notifications

| Event | Recipient | Notification Class | Channels |
|-------|-----------|-------------------|----------|
| Booking created | Artist | `BookingRequestNotification` | Email, FCM Push |
| Booking accepted | Client | `BookingAcceptedNotification` | Email, FCM Push |
| Booking declined | Client | `BookingDeclinedNotification` | Email, FCM Push |

Push notifications are gated by:
1. User has device tokens registered (`device_tokens` table)
2. User has not disabled the notification type (`notification_preferences` table, `channel=push`)

Email notifications are gated by `email_unsubscribed` flag on the user.

## Consultation Window Configuration

Artists configure consultation windows per day in the Working Hours Editor:
- Each day can have an optional consultation window (start/end time within working hours)
- When set, consultations are only bookable within the window
- Appointments are only bookable outside the window
- When not set, both types can be booked during any working hours
- Consultation duration (15/30/45/60 min) is set in artist settings

## Artist Settings (Booking-Related)

| Setting | Description | Default |
|---------|-------------|---------|
| `books_open` | Whether artist is accepting bookings | `true` |
| `accepts_consultations` | Whether artist accepts consultation requests | `false` |
| `accepts_appointments` | Whether artist accepts appointment requests | `false` |
| `deposit_amount` | Required deposit amount for appointments | `null` |
| `consultation_duration` | Duration of consultations in minutes | `15` |

## Key Files

| Component | Path |
|-----------|------|
| Appointment Controller | `ink-api/app/Http/Controllers/AppointmentController.php` |
| Appointment Model | `ink-api/app/Models/Appointment.php` |
| Booking Request Notification | `ink-api/app/Notifications/BookingRequestNotification.php` |
| Booking Accepted Notification | `ink-api/app/Notifications/BookingAcceptedNotification.php` |
| Booking Declined Notification | `ink-api/app/Notifications/BookingDeclinedNotification.php` |
| Push Preferences Trait | `ink-api/app/Notifications/Traits/RespectsPushPreferences.php` |
| Shared Appointment Service | `inked-in-www/shared/services/appointmentService.ts` |
| RN CalendarDayModal | `inked-in-www/reactnative/app/components/Calendar/CalendarDayModal.tsx` |
| RN BookingFormModal | `inked-in-www/reactnative/app/components/Calendar/BookingFormModal.tsx` |
| RN MessageBubble | `inked-in-www/reactnative/app/components/inbox/MessageBubble.tsx` |
| NextJS BookingModal | `inked-in-www/nextjs/components/BookingModal.tsx` |
| NextJS MessageBubble | `inked-in-www/nextjs/components/inbox/MessageBubble.tsx` |
