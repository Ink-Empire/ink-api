# User Blocking

Documentation for the user blocking system in InkedIn.

## Overview

Users can block other users to prevent unwanted contact. When a user is blocked:
- They cannot send messages to the blocker
- They cannot send booking requests to the blocker
- They are hidden from the blocker's inbox and notifications

## Database Schema

### `user_blocks` Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `blocker_id` | bigint | FK to users - the user doing the blocking |
| `blocked_id` | bigint | FK to users - the user being blocked |
| `reason` | string | Optional reason for blocking (private) |
| `created_at` | timestamp | When the block was created |
| `updated_at` | timestamp | |

**Indexes:**
- Unique constraint on `(blocker_id, blocked_id)` - can only block a user once

## API Endpoints

All endpoints require authentication via `auth:sanctum`.

### Block a User

```
POST /api/users/block
```

**Request Body:**
```json
{
  "user_id": 123,
  "reason": "Inappropriate messages"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | ID of user to block |
| `reason` | string | No | Private reason for blocking |

**Response:**
```json
{
  "success": true,
  "message": "User blocked successfully",
  "blocked_user_ids": [123, 456]
}
```

**Errors:**
- `400` - Cannot block yourself
- `404` - User not found

### Unblock a User

```
POST /api/users/unblock
```

**Request Body:**
```json
{
  "user_id": 123
}
```

**Response:**
```json
{
  "success": true,
  "message": "User unblocked successfully",
  "blocked_user_ids": [456]
}
```

### Get Blocked Users

Blocked user IDs are included in the authenticated user's profile response:

```
GET /api/users/me
```

**Response includes:**
```json
{
  "id": 1,
  "name": "...",
  "blocked_user_ids": [123, 456]
}
```

## Model Methods

### User Model

```php
// Relationships
$user->blockedUsers();      // Users this user has blocked
$user->blockedByUsers();    // Users who have blocked this user

// Check methods
$user->hasBlocked($userId);   // Returns bool - has this user blocked them?
$user->isBlockedBy($userId);  // Returns bool - has that user blocked this user?

// Actions
$user->block($userId, $reason);  // Block a user with optional reason
$user->unblock($userId);         // Unblock a user
```

## Implementation Notes

### Checking Blocks Before Actions

When implementing features that involve user interaction, check for blocks:

```php
// Before allowing a message
if ($sender->isBlockedBy($recipientId) || $recipient->hasBlocked($senderId)) {
    return response()->json(['error' => 'Cannot message this user'], 403);
}

// Before allowing a booking request
if ($artist->hasBlocked($clientId)) {
    return response()->json(['error' => 'Cannot book with this artist'], 403);
}
```

### Filtering Blocked Users from Lists

When returning lists of users (search results, etc.), filter out blocked users:

```php
$users = User::whereNotIn('id', $currentUser->blockedUsers()->pluck('blocked_id'))
    ->whereNotIn('id', $currentUser->blockedByUsers()->pluck('blocker_id'))
    ->get();
```

## Frontend Integration

### Blocking a User

```typescript
const blockUser = async (userId: number, reason?: string) => {
  await api.post('/users/block', { user_id: userId, reason });
};
```

### Checking if User is Blocked

The `blocked_user_ids` array is available in the authenticated user's data:

```typescript
const isBlocked = user.blocked_user_ids.includes(otherUserId);
```

### UI Considerations

- Show "Block" option in user profile menus and conversation headers
- Show "Unblock" option for already-blocked users
- Consider showing a confirmation dialog before blocking
- Hide blocked users from search results and recommendations
- Show appropriate message when trying to interact with a blocking user
