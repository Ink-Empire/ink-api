# Email Verification Flow

This document outlines the email verification process for user registration and authentication across both Next.js (web) and React Native (mobile) platforms.

## Overview

Users must verify their email address before they can access the platform. This helps prevent spam accounts and ensures users have valid contact information.

## Registration Flow

### 1. User Completes Registration

**Endpoint:** `POST /api/register`

**Response (201):**
```json
{
  "message": "Registration successful. Please check your email to verify your account.",
  "requires_verification": true,
  "email": "john@example.com",
  "user": { "id": 123 },
  "token": "1|abc123..."
}
```

The response includes a **temporary token** named `registration-upload` that:
- Expires in **30 minutes**
- Allows the mobile app to poll `/users/me` while awaiting verification
- Allows profile photo upload before verification
- Gets upgraded to a permanent `authToken` upon verification

### 2. Verification Email Sent (Queued)

Upon successful registration, Laravel fires the `Registered` event which triggers:
- `SendEmailVerificationNotification` listener
- Calls `User::sendEmailVerificationNotification()`
- Queues `VerifyEmailNotification` (ShouldQueue) using the `mail.verify-email` blade template

The email contains a signed URL that expires in 60 minutes.

### 3. Slack Notification (Queued)

The `UserObserver::created` hook dispatches `SendSlackNewUserNotification` (queued job) to notify the team of the new signup.

## Platform-Specific Post-Registration Behavior

### Next.js (Web)

After registration, the frontend redirects to `/verify-email?email={encoded_email}` where users see:
- "You're almost done!" message
- Instructions to check their email
- "Resend Verification Email" button

When the user clicks the verification link, it opens in the same browser. The verification endpoint returns a token, and the frontend stores it and redirects to the appropriate page.

### React Native (Mobile)

After registration, the app calls `refreshUser()` which:
1. Fetches `/users/me` using the temporary `registration-upload` token
2. Sets the user in `AuthContext` with `is_email_verified: false`
3. `App.tsx` detects `isAuthenticated && !isEmailVerified` and renders `VerifyEmailGate`

**VerifyEmailGate** behavior:
- Polls `refreshUser()` every **5 seconds**
- User clicks the verification link from their email (opens in a browser, not the app)
- On the backend, verification **upgrades** the `registration-upload` token to a permanent `authToken` (removes 30-minute expiry)
- Next poll detects `is_email_verified: true`
- `App.tsx` transitions from `VerifyEmailGate` to `AuthenticatedApp`

**Non-blocking background tasks** (fire-and-forget after `register()` returns):
- Profile photo upload via presigned S3 URL
- Lead creation (client users with tattoo intent)
- Artist join request notification (queued, if artist selected a studio)

```
React Native Registration Flow
===============================

User taps "Create Account"
        |
        v
+-----------------------+
|  POST /api/register   |
|  (returns temp token, |
|   30 min expiry)      |
+-----------------------+
        |
        v
+-----------------------+     +-----------------------+
|  Fire-and-forget:     |     |  Queued on backend:   |
|  - Photo upload       |     |  - Verification email |
|  - Lead creation      |     |  - Slack notification |
+-----------------------+     +-----------------------+
        |
        v
+-----------------------+
|  refreshUser()        |
|  GET /users/me        |
|  (is_email_verified   |
|   = false)            |
+-----------------------+
        |
        v
+-----------------------+
|  VerifyEmailGate      |
|  polls every 5s       |
+-----------------------+
        |
        v (user verifies in browser)
+-----------------------+
|  VerifyEmailController|
|  - Marks verified     |
|  - Upgrades temp      |
|    token to permanent |
|  - Sends welcome email|
+-----------------------+
        |
        v
+-----------------------+
|  Next poll detects    |
|  is_email_verified    |
|  = true               |
+-----------------------+
        |
        v
+-----------------------+
|  App transitions to   |
|  AuthenticatedApp     |
+-----------------------+
```

## Email Verification

### User Clicks Verification Link

**Endpoint:** `GET /api/email/verify/{id}/{hash}`

**Response (200):**
```json
{
  "message": "Email verified successfully.",
  "token": "1|abc123...",
  "user": { ... },
  "redirect_url": "/dashboard"
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
- Any `registration-upload` token is upgraded to permanent `authToken`
- A new `authToken` is created for the browser session
- Welcome email is sent via `SendWelcomeNotification` job (queued)

**Studio Accounts (type_id=3):**
- `AuthController::register()` creates/claims the studio record during registration (before verification)
- The studio exists but has no `image_id` yet at this point
- During registration, after the temp token is returned, the frontend uploads the studio image to S3 and stores the `uploadedImageId` in `localStorage` (web) or handles it in-app (RN)
- After verification, `dashboard.tsx` reads `pendingStudioData` from `localStorage` and links the image:
  - If studio already exists (normal case): calls `POST /studios/{id}/image` with the `uploadedImageId`
  - If studio doesn't exist (edge case): calls `POST /studios` with `image_id` in payload
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

## Correcting a Mistyped Address

A user who mistypes their address at registration is otherwise locked out with
no self-serve recovery: `register()` has no confirmation step, the login 403
path resends to the same wrong address, and every endpoint that could change
the email sits behind auth middleware an unverified user cannot pass. Their
only option was to register again, stranding the first attempt holding an
email, username and slug nobody can reclaim.

**Endpoint:** `POST /api/email/correct`

Unauthenticated by necessity. The old address plus the password are the
credential, which is what the user has in hand at the point they discover the
problem: the login 403 screen already carries their email and they just typed
their password.

**Request:**
```json
{
  "email": "john@exampel.com",
  "password": "...",
  "new_email": "john@example.com"
}
```

**Response (200):**
```json
{
  "message": "Email updated. Check your inbox for a new verification link.",
  "verification": {
    "email": "john@example.com",
    "requires_verification": true
  }
}
```

**Behavior:**
- `EmailCorrectionService` looks the account up by the old address and checks
  the password with `Hash::check`
- An unknown address and a wrong password return the same 422 message, so the
  endpoint cannot be used to find out which addresses are registered
- An account that has already verified is refused; it should change its email
  from account settings instead
- The new address passes `unique:users`, so it cannot collide with an existing
  account
- The address is written and `VerifyEmailNotification` is re-sent

**Link invalidation:** the signed verification URL carries
`sha1($user->getEmailForVerification())` as its hash and `VerifyEmailController`
compares it against the address currently on the record. Writing a new address
is therefore what invalidates every link already mailed to the old one; those
links now fail `hash_equals` and return 403. There is a test covering this,
because nothing in the code says so out loud.

**Rate limits:** three layers, since a successful call mails an address the
caller chose.

| Layer | Limit | Key |
|-------|-------|-----|
| Route middleware | 6 requests / minute | IP |
| `CorrectEmailRequest` | 5 failed attempts / minute | old email + IP |
| `EmailCorrectionService` | 3 successful changes / hour | user id |

The middle limiter mirrors `LoginRequest::ensureIsNotRateLimited()`. It is
cleared on success and hit on failure. The service limiter is the one that
stops a caller with valid credentials using the endpoint as a slow relay.

**Not done on purpose:**
- The old address is not notified. It is unverified by definition and usually
  belongs to a stranger or nobody, so a notice would be unsolicited mail about
  an account that person has nothing to do with.
- Existing tokens are not revoked. The caller proved the password, and the
  React Native gate is polling `/users/me` with the `registration-upload`
  token while it makes this call.

### Known wrinkle: the two windows disagree

The `registration-upload` token expires in 30 minutes. The verification link
expires in 60. Worse, the 60 is not configured anywhere: `auth.verification.expire`
is absent from `config/auth.php`, so the value comes from the hardcoded fallback
in `VerifyEmailNotification::verificationUrl()`. A user can therefore still hold
a valid verification link after the token that was supposed to accompany it has
expired. This endpoint does not depend on either window, but the mismatch is
still there for the rest of the flow.

### Known wrinkle: the unverified-login resend is unthrottled

`AuthController::login()` calls `sendEmailVerificationNotification()` on every
attempt by an unverified user, with no limiter of its own. That path mails an
attacker-chosen address on request, which is the same concern the new endpoint
is carefully guarded against.

## Token Lifecycle

| Stage | Token Name | Expiry | Purpose |
|-------|-----------|--------|---------|
| After registration | `registration-upload` | 30 minutes | Polling `/users/me`, profile photo upload |
| After verification | `authToken` (upgraded) | None | Permanent mobile session |
| After verification | `authToken` (new) | None | Browser session from verification redirect |
| After login | `authToken` | None | Normal authenticated session |

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
| `app/Http/Controllers/Auth/EmailCorrectionController.php` | Mistyped-address recovery endpoint |
| `app/Http/Requests/Auth/CorrectEmailRequest.php` | Validation and failed-attempt throttle |
| `app/Services/EmailCorrectionService.php` | Credential check, address write, send cap |
| `app/Http/Resources/EmailVerificationResource.php` | Verification state for an unauthenticated caller |
| `tests/Feature/Flows/EmailCorrectionTest.php` | Coverage for the recovery flow |
| `app/Notifications/VerifyEmailNotification.php` | Verification email notification (queued) |
| `app/Jobs/SendSlackNewUserNotification.php` | Slack notification for new signups (queued) |
| `app/Observers/UserObserver.php` | Dispatches Slack notification on user creation |
| `app/Models/User.php` | `sendEmailVerificationNotification()` method |
| `app/Providers/EventServiceProvider.php` | Registered event listener |
| `resources/views/mail/verify-email.blade.php` | Email template |

### Next.js Frontend

| File | Purpose |
|------|---------|
| `pages/register.tsx` | Registration flow |
| `pages/login.tsx` | Login with verification check |
| `pages/verify-email.tsx` | Verification status page |
| `components/CorrectEmailForm.tsx` | Inline mistyped-address correction form |
| `services/authService.ts` | `correctEmail()` |
| `pages/dashboard.tsx` | Post-verification studio creation |
| `contexts/AuthContext.tsx` | Auth state and login handler |

### React Native

| File | Purpose |
|------|---------|
| `app/screens/auth/RegisterScreen.tsx` | Multi-step registration flow |
| `app/components/auth/VerifyEmailGate.tsx` | Polls for verification, auto-transitions |
| `app/components/auth/CorrectEmailForm.tsx` | Mistyped-address correction form |
| `app/screens/auth/VerifyEmailScreen.tsx` | Verification status screen |
| `shared/api/client.ts` | `createAuthApi().correctEmail()` |
| `app/contexts/AuthContext.tsx` | Auth state, register/refreshUser |
| `App.tsx` | Routes to VerifyEmailGate when unverified |
| `lib/api.ts` | API client with token storage |

## Related Documentation

- [Artist Signup and Onboarding](flows/artist-signup-onboarding.md)
- [Studio Registration and Management](flows/studio-registration-management.md)
