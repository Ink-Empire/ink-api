# Messaging System

Documentation for the InkedIn messaging/conversation system.

## Overview

The messaging system supports real-time conversations between artists and clients. It handles booking inquiries, consultations, design discussions, and guest spot requests.

## Database Schema

### Tables

#### `conversations`
Represents a conversation thread between two users.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `type` | enum | Conversation type (see below) |
| `appointment_id` | bigint | Optional link to appointment |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Conversation Types:**
- `booking` - Tattoo booking inquiry
- `consultation` - General consultation
- `guest-spot` - Guest spot arrangement between artists/studios
- `design` - Design discussion

#### `conversation_participants`
Links users to conversations (supports 2+ participants).

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `conversation_id` | bigint | FK to conversations |
| `user_id` | bigint | FK to users |
| `last_read_at` | timestamp | When user last read messages |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

#### `messages`
Individual messages within conversations.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `conversation_id` | bigint | FK to conversations (nullable for legacy) |
| `appointment_id` | bigint | FK to appointments (legacy) |
| `sender_id` | bigint | FK to users |
| `recipient_id` | bigint | FK to users (nullable for conversation-based) |
| `parent_message_id` | bigint | FK for threading |
| `content` | text | Message content |
| `message_type` | enum | Threading: `initial`, `reply` |
| `type` | enum | Content type (see below) |
| `metadata` | json | Type-specific data |
| `read_at` | timestamp | When recipient read |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Message Content Types:**
| Type | Description | Metadata |
|------|-------------|----------|
| `text` | Plain text message | - |
| `image` | Image attachment | - |
| `booking_card` | Booking details card | `{ date, time, duration, deposit }` |
| `deposit_request` | Request for deposit payment | `{ amount, appointment_id }` |
| `system` | Generic system notification | `{ event_type, details }` |
| `design_share` | Share a tattoo design | `{ tattoo_id, notes }` |
| `price_quote` | Pricing breakdown | `{ items[], total, valid_until }` |
| `appointment_reminder` | Reminder notification | `{ appointment_id, date, time, reminder_type }` |
| `appointment_confirmed` | Confirmation notification | `{ appointment_id, date, time }` |
| `appointment_cancelled` | Cancellation notification | `{ appointment_id, reason }` |
| `deposit_received` | Payment confirmation | `{ amount, appointment_id }` |
| `aftercare` | Aftercare instructions | `{ instructions[], pdf_url }` |

#### `message_attachments`
Links images to messages.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `message_id` | bigint | FK to messages |
| `image_id` | bigint | FK to images |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

## API Endpoints

All endpoints require authentication via `auth:sanctum`.

### Conversations

#### List Conversations
```
GET /api/conversations
```

**Query Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `type` | string | Filter by conversation type |
| `unread` | boolean | Filter to unread only |
| `search` | string | Search by participant name or message content |
| `limit` | int | Results per page (default: 20) |

**Response:**
```json
{
  "conversations": [
    {
      "id": 1,
      "type": "booking",
      "participant": {
        "id": 21,
        "name": "Marcus Chen",
        "username": "marcus",
        "initials": "MC",
        "image": { "id": 1, "uri": "https://..." },
        "is_online": true,
        "last_seen_at": "2025-12-18T10:00:00Z"
      },
      "last_message": {
        "id": 5,
        "content": "Sounds great!",
        "type": "text",
        "sender_id": 21,
        "created_at": "2025-12-18T10:00:00Z"
      },
      "unread_count": 2,
      "appointment": null,
      "created_at": "2025-12-15T09:00:00Z",
      "updated_at": "2025-12-18T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

#### Get Conversation with Messages
```
GET /api/conversations/{id}
```

**Query Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `before` | int | Message ID for cursor pagination |
| `limit` | int | Messages per request (default: 50) |

**Response:**
```json
{
  "conversation": { ... },
  "messages": [
    {
      "id": 1,
      "conversation_id": 1,
      "sender_id": 21,
      "sender": {
        "id": 21,
        "name": "Marcus Chen",
        "username": "marcus",
        "initials": "MC",
        "image": { "id": 1, "uri": "https://..." }
      },
      "content": "Hey! Love your work.",
      "type": "text",
      "metadata": null,
      "attachments": [],
      "created_at": "2025-12-15T09:00:00Z",
      "updated_at": "2025-12-15T09:00:00Z"
    }
  ]
}
```

#### Create Conversation
```
POST /api/conversations
```

**Request Body:**
```json
{
  "participant_id": 21,
  "type": "booking",
  "initial_message": "Hi! I'm interested in getting a tattoo.",
  "appointment_id": null
}
```

#### Get Unread Count
```
GET /api/conversations/unread-count
```

**Response:**
```json
{
  "unread_count": 5
}
```

#### Mark as Read
```
PUT /api/conversations/{id}/read
```

Marks both:
1. `conversation_participants.last_read_at` - For unread count calculation
2. `messages.read_at` - For individual message read receipts (messages where `recipient_id` = current user)

### Messages

#### Get Messages (paginated)
```
GET /api/conversations/{id}/messages
```

**Query Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `before` | int | Message ID for cursor pagination |
| `limit` | int | Messages per request (default: 50) |

#### Send Message
```
POST /api/conversations/{id}/messages
```

**Request Body:**
```json
{
  "content": "Hello!",
  "type": "text",
  "metadata": null,
  "attachment_ids": [1, 2]
}
```

#### Send Booking Card
```
POST /api/conversations/{id}/messages/booking-card
```

**Request Body:**
```json
{
  "date": "Dec 28, 2025",
  "time": "2:00 PM",
  "duration": "3-4 hours",
  "deposit_amount": "$150 NZD"
}
```

#### Send Deposit Request
```
POST /api/conversations/{id}/messages/deposit-request
```

**Request Body:**
```json
{
  "amount": "$150 NZD",
  "appointment_id": 1
}
```

#### Send Design Share
```
POST /api/conversations/{id}/messages/design-share
```

**Request Body:**
```json
{
  "tattoo_id": 42,
  "notes": "Here's the design we discussed!"
}
```

#### Send Price Quote
```
POST /api/conversations/{id}/messages/price-quote
```

**Request Body:**
```json
{
  "items": [
    { "description": "Design fee", "amount": "$100" },
    { "description": "Tattoo (3-4 hours)", "amount": "$450" }
  ],
  "total": "$550 NZD",
  "valid_until": "2025-12-31",
  "notes": "Price includes touch-up session"
}
```

## Models

### Conversation

**Relationships:**
- `appointment()` - BelongsTo Appointment
- `participants()` - HasMany ConversationParticipant
- `users()` - BelongsToMany User (through participants)
- `messages()` - HasMany Message
- `latestMessage()` - HasOne Message (most recent)

**Scopes:**
- `forUser($userId)` - Conversations where user is participant
- `ofType($type)` - Filter by conversation type
- `withUnreadForUser($userId)` - Include unread count

**Methods:**
- `getOtherParticipant($userId)` - Get the other user in conversation
- `getUnreadCountForUser($userId)` - Count unread messages for user

### Message

**Relationships:**
- `conversation()` - BelongsTo Conversation
- `sender()` - BelongsTo User
- `recipient()` - BelongsTo User
- `attachments()` - HasMany MessageAttachment
- `parentMessage()` - BelongsTo Message (threading)
- `replies()` - HasMany Message (threading)

**Methods:**
- `isRead()` - Check if message was read
- `markAsRead()` - Mark message as read
- `isBookingCard()` - Check if booking card type
- `isDepositRequest()` - Check if deposit request type

## MessageService

The `MessageService` class provides methods for creating system-generated messages:

```php
use App\Services\MessageService;

$messageService = new MessageService();

// Send appointment confirmation
$messageService->sendAppointmentConfirmed($conversationId, $appointmentId, 'Dec 28, 2025', '2:00 PM');

// Send appointment reminder (types: '24h', '1h', 'week')
$messageService->sendAppointmentReminder($conversationId, $appointmentId, 'Dec 28, 2025', '2:00 PM', '24h');

// Send cancellation notice
$messageService->sendAppointmentCancelled($conversationId, $appointmentId, 'Client requested cancellation');

// Send deposit received confirmation
$messageService->sendDepositReceived($conversationId, '$150 NZD', $appointmentId);

// Send aftercare instructions
$messageService->sendAftercare($conversationId, $senderId, [
    'Keep the bandage on for 2-4 hours',
    'Wash gently with unscented soap',
    'Apply thin layer of aftercare ointment'
], 'https://example.com/aftercare.pdf');

// Send generic system notification
$messageService->sendSystemNotification($conversationId, 'Your session has been rescheduled');
```

## Image Attachments

Messages can include image attachments. Images are uploaded via presigned S3 URLs, then attached to messages.

### Uploading Images for Messages

1. Request presigned URL(s):
```
POST /api/images/presigned
```

**Request Body:**
```json
{
  "purpose": "message",
  "files": [
    { "filename": "design.jpg", "content_type": "image/jpeg" }
  ]
}
```

2. Upload image directly to S3 using the presigned URL

3. Send message with attachment IDs:
```
POST /api/conversations/{id}/messages
```

**Request Body:**
```json
{
  "content": "Here's the reference image",
  "type": "image",
  "attachment_ids": [123, 124]
}
```

### Supported Formats
- JPEG (.jpg, .jpeg)
- PNG (.png)
- WebP (.webp)
- GIF (.gif)

**Note:** SVG files are not supported for security reasons.

## Artist Watermark Protection

Artists can configure automatic watermarking to protect their designs when sharing with clients.

### How It Works

1. Artist uploads a watermark image (logo, signature, etc.) in their profile settings
2. Artist configures watermark settings:
   - **Enabled/Disabled** - Toggle watermarking on or off
   - **Opacity** - 0-100% transparency (default: 50%)
   - **Position** - Where to place the watermark:
     - `top-left`
     - `top-right`
     - `bottom-left`
     - `bottom-right` (default)
     - `center`
3. When the artist sends a `design_share` or `image` type message, the watermark is automatically applied
4. The watermarked image is saved as a new file (original is preserved)

### Watermark Settings API

#### Get Artist Settings (includes watermark)
```
GET /api/artists/{id}/settings
```

**Response includes:**
```json
{
  "watermark_enabled": true,
  "watermark_opacity": 50,
  "watermark_position": "bottom-right",
  "watermark_image": {
    "id": 123,
    "uri": "https://..."
  }
}
```

#### Update Watermark Settings
```
PUT /api/artists/{id}/settings
```

**Request Body (any combination):**
```json
{
  "watermark_enabled": true,
  "watermark_opacity": 75,
  "watermark_position": "center",
  "watermark_image_id": 123
}
```

### Technical Details

- Watermarks are scaled to max 20% of the source image width
- WebP images are automatically converted for processing
- Uses Imagick driver when available (better format support), falls back to GD
- Watermarked images are saved as JPEG at 90% quality
- Original images are never modified

### Database Schema

Added to `artist_settings` table:

| Column | Type | Description |
|--------|------|-------------|
| `watermark_image_id` | bigint | FK to images table |
| `watermark_opacity` | int | 0-100 (default: 50) |
| `watermark_position` | string | Position enum (default: bottom-right) |
| `watermark_enabled` | boolean | Toggle (default: false) |

## Email Notifications

### New Message Notification

When a user receives a new message, they get an email notification.

**Trigger:** `ConversationController::sendMessage()` after successful message creation

**Notification Class:** `App\Notifications\NewMessageNotification`

**Email Template:** `resources/views/mail/new-message.blade.php`

**Content:**
- Subject: "New message from {sender} - InkedIn"
- Body: Simple notification directing recipient to check their inbox
- CTA: "View Message" button linking to `/inbox`

**Note:** Notification failures are logged but don't block message sending.

## Future Enhancements

### Additional Message Types (Planned)

| Type | Description | Metadata |
|------|-------------|----------|
| `availability` | Available time slots | `{ slots[], timezone }` |
| `consent_form` | Consent form link | `{ form_url, signed }` |
| `reschedule_request` | Request to change time | `{ original_date, proposed_dates[] }` |
| `review_request` | Request for client review | `{ appointment_id }` |
| `portfolio_share` | Share multiple designs | `{ tattoo_ids[], notes }` |

### Features Roadmap

- [ ] Real-time messaging via WebSockets/Pusher
- [ ] Message reactions
- [ ] Message editing/deletion
- [ ] Typing indicators
- [ ] Read receipts (seen by)
- [ ] Message search within conversation
- [ ] File attachments (PDF, documents)
- [ ] Voice messages
- [ ] Message templates for artists
- [ ] Auto-responses / away messages
- [ ] Conversation archiving
- [ ] Block/report functionality

### Completed Features

- [x] Image attachments in messages
- [x] Artist watermark protection for designs
- [x] New message email notifications

## Seed Data

Sample conversations are seeded via `ConversationSeeder`:

```bash
php artisan db:seed --class=ConversationSeeder
```

Seed data is located in `database/seed-data/conversations.json`.
