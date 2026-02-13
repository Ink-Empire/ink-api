# Artist Signup and Onboarding Flow

This document describes the complete artist registration and onboarding process for InkedIn.

## Flow Diagram

```mermaid
flowchart TD
    subgraph Registration["Registration Flow"]
        A[Start: /register] --> B[Select User Type]
        B --> |Artist| C[Style Selection]
        C --> D[User Details]
        D --> D1[Enter Name & Username]
        D1 --> D2{Username Available?}
        D2 --> |No| D1
        D2 --> |Yes| D3[Set Location]
        D3 --> D4[Upload Profile Photo]
        D4 --> D5[Select Studio Affiliation]
        D5 --> E[Account Setup]
        E --> E1[Enter Email]
        E1 --> E2{Email Available?}
        E2 --> |No| E1
        E2 --> |Yes| E3[Create Password]
        E3 --> E4{Password Valid?}
        E4 --> |No| E3
        E4 --> |Yes| F[Submit Registration]
    end

    subgraph API["API: POST /api/register"]
        F --> G[Validate All Fields]
        G --> H[Create User Record]
        H --> I[Attach Selected Styles]
        I --> J{Studio Selected?}
        J --> |Yes| K[Link to Studio & Mark Claimed]
        J --> |No| L[Skip Studio]
        K --> M[Fire Registered Event]
        L --> M
        M --> N[Send Verification Email]
    end

    subgraph Verification["Email Verification"]
        N --> O[User Receives Email]
        O --> P[Click Verification Link]
        P --> Q[GET /api/verify-email/id/hash]
        Q --> R{Valid Hash?}
        R --> |No| S[Show Error]
        R --> |Yes| T[Mark Email Verified]
        T --> U[Generate Auth Token]
        U --> V[Send Welcome Email]
        V --> W[Redirect to /dashboard]
    end

    subgraph ProfileSetup["Profile Completion"]
        W --> X[Artist Dashboard]
        X --> Y[Complete Profile]
        Y --> Y1[Update Bio & About]
        Y --> Y2[Set Working Hours]
        Y --> Y3[Configure Booking Settings]
        Y --> Y4[Set Rates & Deposits]
        Y --> Y5[Configure Watermark]
    end

    subgraph Portfolio["Portfolio Upload"]
        X --> Z[Upload Portfolio]
        Z --> Z1{Upload Method?}
        Z1 --> |Single| AA[Individual Tattoo Upload]
        Z1 --> |Bulk| AB[Bulk ZIP Upload]

        AA --> AA1[Upload Images to S3]
        AA1 --> AA2[Set Title & Description]
        AA2 --> AA3[Select Placement]
        AA3 --> AA4[Choose Primary Style]
        AA4 --> AA5[Add Tags]
        AA5 --> AA6[POST /api/tattoos/create]
        AA6 --> AA7[AI Tag Generation Job]
        AA7 --> AA8[Index in Elasticsearch]

        AB --> AB1[Upload ZIP File]
        AB1 --> AB2[System Scans Images]
        AB2 --> AB3[Edit Metadata Per Tattoo]
        AB3 --> AB4[POST /api/bulk-uploads/id/publish]
        AB4 --> AA8
    end

    AA8 --> AC[Artist Profile Live]

    style A fill:#e1f5fe
    style F fill:#fff3e0
    style T fill:#e8f5e9
    style AC fill:#c8e6c9
    style S fill:#ffcdd2
```

## Step-by-Step Breakdown

### 1. Registration Flow

| Step | Component | Endpoint | Description |
|------|-----------|----------|-------------|
| User Type | `UserTypeSelection.tsx` | - | Select "Artist" |
| Styles | `StylesSelection.tsx` | `GET /api/styles` | Choose specialties |
| Details | `UserDetails.tsx` | `POST /api/check-availability` | Name, username, location |
| Studio | `StudioAutocomplete` | `POST /api/studios/lookup-or-create` | Optional studio affiliation |
| Account | `AccountSetup.tsx` | `POST /api/check-availability` | Email & password |
| Submit | - | `POST /api/register` | Create account |

### 2. Email Verification

| Step | Endpoint | Description |
|------|----------|-------------|
| Send Email | Automatic | Triggered by `Registered` event |
| Verify | `GET /api/verify-email/{id}/{hash}` | Validates signed URL |
| Resend | `POST /api/email/verification-notification` | Rate limited: 6/min |

### 3. Profile Completion

| Section | Endpoint | Description |
|---------|----------|-------------|
| Photo | `POST /api/users/profile-photo` | S3 presigned upload |
| Bio | `PUT /api/users/{id}` | Update about text |
| Styles | `PUT /api/users/{id}` | Sync style relationships |
| Hours | `POST /api/artists/{id}/working-hours` | Set availability |
| Settings | `PUT /api/artists/{id}/settings` | Booking preferences, rates |

### 4. Portfolio Upload

#### Individual Upload
1. Upload images via S3 presigned URLs
2. Set metadata (title, description, placement)
3. Select primary style + additional styles
4. Add tags manually
5. `POST /api/tattoos/create`
6. `GenerateAiTagsJob` dispatched for async AI tag generation
7. `IndexTattooJob` dispatched for async Elasticsearch indexing (tattoo + artist re-index)
8. Frontend shows "Tattoo published! It will appear in search shortly."

#### Bulk Upload
1. Upload ZIP file
2. System extracts and scans images
3. Edit metadata for each tattoo
4. `POST /api/bulk-uploads/{id}/publish`
5. `PublishBulkUploadItems` job creates all tattoos, then batch indexes to Elasticsearch
6. Falls back to individual `IndexTattooJob` dispatches if batch indexing fails
7. Frontend shows "Your tattoos are being processed and will appear in search shortly."

## Password Requirements

- Minimum 8 characters
- At least 1 uppercase letter
- At least 1 lowercase letter
- At least 1 number
- At least 1 symbol (!@#$%^&*...)

## Username Requirements

- Maximum 30 characters
- Only alphanumeric, periods, underscores
- Must be unique

## Key Files

| Component | Path |
|-----------|------|
| Registration Page | `inked-in-www/nextjs/pages/register.tsx` |
| Onboarding Wizard | `inked-in-www/nextjs/components/Onboarding/OnboardingWizard.tsx` |
| Auth Controller | `ink-api/app/Http/Controllers/AuthController.php` |
| Verify Email | `ink-api/app/Http/Controllers/Auth/VerifyEmailController.php` |
| Artist Controller | `ink-api/app/Http/Controllers/ArtistController.php` |
| Tattoo Controller | `ink-api/app/Http/Controllers/TattooController.php` |
| Profile Page | `inked-in-www/nextjs/pages/profile.tsx` |
| Tattoo Upload | `inked-in-www/nextjs/pages/tattoos/update.tsx` |
| Bulk Upload | `inked-in-www/nextjs/pages/bulk-upload/index.tsx` |
