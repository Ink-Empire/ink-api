# Booking Flow

## Client Booking Request

```mermaid
sequenceDiagram
    participant C as Client (NextJS/RN)
    participant API as ink-api
    participant DB as Database
    participant N as Notifications

    C->>API: GET /artists/{id}/available-slots?date=...&type=...
    API->>DB: Query artist_availability (day_of_week)
    API->>DB: Query artist_settings (consultation_duration)
    API->>DB: Query appointments (date, pending/booked)
    API-->>C: { slots, working_hours, consultation_window, consultation_duration }

    C->>API: POST /appointments/create
    API->>DB: Validate artist exists, not blocked
    API->>DB: Create appointment (status=pending)
    API->>DB: Find/create conversation
    API->>DB: Create initial message (booking_card)
    API->>N: Send BookingRequestNotification email
    API->>N: Sync to Google Calendar (if connected)
    API-->>C: AppointmentResource
```

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

## Artist Respond to Request

```mermaid
sequenceDiagram
    participant A as Artist
    participant API as ink-api
    participant DB as Database
    participant N as Notifications

    A->>API: POST /appointments/{id}/respond { action: accept/decline }

    alt Accept
        API->>DB: Update status to "booked"
        API->>N: Send BookingAcceptedNotification to client
        API->>N: Sync to Google Calendar
        API->>DB: Add system message to conversation
    else Decline
        API->>DB: Update status to "cancelled"
        API->>N: Send BookingDeclinedNotification to client
        API->>N: Remove from Google Calendar
        API->>DB: Add system message to conversation
    end

    API-->>A: AppointmentResource
```

## Consultation Window Configuration

Artists configure consultation windows per day in the Working Hours Editor:
- Each day can have an optional consultation window (start/end time within working hours)
- When set, consultations are only bookable within the window
- Appointments are only bookable outside the window
- When not set, both types can be booked during any working hours
- Consultation duration (15/30/45/60 min) is set in artist settings
