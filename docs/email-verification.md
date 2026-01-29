# Email Verification Flow

This document outlines the email verification process for user registration and authentication.

## Overview

Users must verify their email address before they can access the platform. This helps prevent spam accounts and ensures users have valid contact information.

## Registration Flow

### 1. User Completes Registration

**Endpoint:** `POST /api/register`

**Request:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "SecurePass123!",
  "username": "johndoe",
  "slug": "johndoe",
  "type": "user"
}
```

**Response (201):**
```json
{
  "message": "Registration successful. Please check your email to verify your account.",
  "requires_verification": true,
  "email": "john@example.com"
}
```

**Important:** No authentication token is returned. The user is NOT logged in after registration.

### 2. Verification Email Sent

Upon successful registration, Laravel fires the `Registered` event which triggers:
- `SendEmailVerificationNotification` listener
- Calls `User::sendEmailVerificationNotification()`
- Sends custom `VerifyEmailNotification` using the `mail.verify-email` blade template

The email contains a signed URL that expires in 60 minutes.

### 3. Frontend Redirect

After registration, the frontend redirects to `/verify-email?email={encoded_email}` where users see:
- "You're almost done!" message
- Instructions to check their email
- Spam folder warning
- "Resend Verification Email" button

## Email Verification

### User Clicks Verification Link

**Endpoint:** `GET /api/email/verify/{id}/{hash}`

The link from the email directs to the frontend at `/verify-email?url={encoded_api_url}&email={encoded_email}`, which then calls the API verification endpoint.

**Response (200):**
```json
{
  "message": "Email verified successfully.",
  "token": "1|abc123...",
  "user": { ... },
  "redirect_url": "/dashboard"  // or "/tattoos" for clients (type_id=1)
}
```

**Redirect URL by User Type:**
| type_id | User Type | Redirect |
|---------|-----------|----------|
| 1 | Client | `/tattoos` |
| 2 | Artist | `/dashboard` |
| 3 | Studio | `/dashboard` |

**After Verification:**
- `email_verified_at` timestamp is set
- `is_email_verified` boolean is set to `true`
- Welcome email is sent via `SendWelcomeNotification` job
- User receives an authentication token and is logged in

**Studio Accounts (type_id=3):**
- Pending studio data stored in `localStorage` during registration
- After redirect to `/dashboard`, the pending data is processed
- If `existingStudioId` present: `POST /api/studios/{id}/claim` (claims Google Places studio)
- Otherwise: `POST /api/studios` (creates new studio)
- See `docs/flows/studio-registration-management.md` for full flow

### Already Verified

If a user clicks the verification link again:

**Response (200):**
```json
{
  "message": "Email already verified.",
  "already_verified": true,
  "token": "1|abc123...",
  "user": { ... },
  "redirect_url": "/dashboard"
}
```

## Login Without Verification

If a user attempts to login before verifying their email:

**Endpoint:** `POST /api/login`

**Request:**
```json
{
  "email": "john@example.com",
  "password": "SecurePass123!"
}
```

**Response (403):**
```json
{
  "message": "Please verify your email address before logging in.",
  "requires_verification": true,
  "email": "john@example.com"
}
```

**Behavior:**
- Credentials are validated first (wrong password returns 422)
- If credentials are correct but email is unverified:
  - Verification email is automatically resent
  - 403 response returned with `requires_verification: true`
- Frontend redirects to `/verify-email?email={encoded_email}`

## Resend Verification Email

**Endpoint:** `POST /api/email/verification-notification`

**Request:**
```json
{
  "email": "john@example.com"
}
```

**Response (200):**
```json
{
  "message": "Verification link sent!"
}
```

## Database Fields

The `users` table has two verification-related fields:

| Field | Type | Description |
|-------|------|-------------|
| `email_verified_at` | timestamp | Laravel's built-in verification timestamp |
| `is_email_verified` | boolean | Custom field for easier filtering/queries |

Both are set when the user verifies their email.

## Key Files

### Backend (ink-api)

| File | Purpose |
|------|---------|
| `app/Http/Controllers/AuthController.php` | Registration and login logic |
| `app/Http/Controllers/Auth/VerifyEmailController.php` | Email verification handling |
| `app/Notifications/VerifyEmailNotification.php` | Verification email notification |
| `app/Models/User.php` | `sendEmailVerificationNotification()` method |
| `app/Providers/EventServiceProvider.php` | Registered event listener |
| `resources/views/mail/verify-email.blade.php` | Email template |

### Frontend (inked-in-www)

| File | Purpose |
|------|---------|
| `pages/register.tsx` | Registration flow |
| `pages/login.tsx` | Login with verification check |
| `pages/verify-email.tsx` | Verification status page |
| `pages/dashboard.tsx` | Post-verification studio creation |
| `contexts/AuthContext.tsx` | Auth state and login handler |

## Related Documentation

- [Artist Signup and Onboarding](flows/artist-signup-onboarding.md)
- [Studio Registration and Management](flows/studio-registration-management.md)

## Flow Diagrams

### Registration
```
User submits registration
        │
        ▼
┌─────────────────────┐
│  Create user        │
│  (unverified)       │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Fire Registered    │
│  event              │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Send verification  │
│  email              │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Return 201         │
│  (no token)         │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Redirect to        │
│  /verify-email      │
└─────────────────────┘
```

### Login (Unverified User)
```
User submits login
        │
        ▼
┌─────────────────────┐
│  Validate           │
│  credentials        │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Check email        │
│  verified?          │
└─────────────────────┘
        │
        ▼ NO
┌─────────────────────┐
│  Resend             │
│  verification email │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Return 403         │
│  requires_          │
│  verification: true │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Redirect to        │
│  /verify-email      │
└─────────────────────┘
```

### Email Verification
```
User clicks email link
        │
        ▼
┌─────────────────────┐
│  Validate signed    │
│  URL                │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Mark email         │
│  as verified        │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Send welcome       │
│  email              │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Create auth        │
│  token              │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  Return token       │
│  + redirect URL     │
└─────────────────────┘
        │
        ▼
┌─────────────────────┐
│  User is now        │
│  authenticated      │
└─────────────────────┘
```
