# Tattoo Upload Process

This document outlines the complete tattoo upload flow, including image processing, tagging, and search indexing.

## Overview

Both artists and clients can upload tattoos. When a tattoo is uploaded, the system:
1. Uploads and processes images
2. Creates the tattoo record with metadata
3. Attaches user-selected tags
4. Dispatches `GenerateAiTagsJob` for async AI tag generation
5. Dispatches `IndexTattooJob` for async Elasticsearch indexing
6. Returns the tattoo immediately (AI tags and search indexing happen in background)

### Client Uploads vs Artist Uploads

- **Artist uploads**: `approval_status` = APPROVED, `is_visible` = true, tattoo appears in search immediately after indexing.
- **Client uploads**: If the client tags an artist, `approval_status` = PENDING (artist must approve). If no artist is tagged, `approval_status` = USER_ONLY. `is_visible` = false until approved.
- The uploader's name, username, and slug are indexed in Elasticsearch (`uploader_name`, `uploader_username`, `uploader_slug`) so tattoos are discoverable by searching for the uploader.
- On the tattoo detail page, if the uploader is different from the artist, an "Uploaded by {username}" attribution links to the uploader's profile.

## Upload Flow

### Step 1: Form Submission

**Endpoint:** `POST /api/tattoos/create`

**Artist Upload Request (multipart/form-data):**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `files` | File[] | Yes | Up to 5 image files |
| `description` | string | Yes | Tattoo description |
| `primary_style_id` | int | Yes | Primary tattoo style |
| `additional_style_ids` | JSON array | No | Additional style IDs |
| `tag_ids` | JSON array | No | User-selected tag IDs |

**Client Upload Request (JSON):**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `image_ids` | int[] | Yes | IDs of images pre-uploaded via S3 presigned URLs |
| `title` | string | No | Tattoo name |
| `description` | string | No | Story or context from the client |
| `tagged_artist_id` | int | No | Artist to tag (triggers approval workflow) |
| `studio_id` | int | No | Studio to associate with the tattoo |
| `attributed_studio_name` | string | No | Studio name (stored on tattoo for display) |
| `attributed_location` | string | No | Location where tattoo was done |
| `attributed_location_lat_long` | string | No | Lat/long for location |
| `attributed_artist_name` | string | No | Artist name (for unregistered artists) |
| `artist_invite_email` | string | No | Email to invite unregistered artist |

### Step 2: Image Processing

1. Images are uploaded to cloud storage (S3)
2. First image becomes the primary image
3. All images are attached to tattoo via `tattoos_images` pivot table

### Step 3: Tag Processing

#### User-Selected Tags
- Tags provided in `tag_ids` are validated against the `tags` table
- Valid tags are attached via `tattoos_tags` pivot table

#### AI Tag Generation
- Uses OpenAI GPT-4o-mini Vision API
- Analyzes each tattoo image
- Generates 3-5 descriptive noun tags per image
- Matches AI suggestions to existing tags in master list
- Only attaches tags that exist in the database

### Step 4: Response

**Response:**
```json
{
  "tattoo": {
    "id": 123,
    "description": "...",
    "primary_style_id": 1,
    "styles": [...],
    "tags": [...],
    "images": [...]
  },
  "ai_suggested_tags": [
    { "id": 45, "name": "rose", "slug": "rose" },
    { "id": 67, "name": "skull", "slug": "skull" }
  ]
}
```

The `ai_suggested_tags` array contains only tags that:
- Were suggested by AI
- Exist in the master tag list
- Were NOT already selected by the user

### Step 5: User Reviews AI Suggestions (Frontend)

After successful upload, the frontend shows a modal with AI suggestions:
- User can click to add any relevant suggestions
- Uses `POST /api/tattoos/{id}/tags/add` to add individual tags
- Uses `POST /api/tattoos/{id}/tags` to set all tags at once

### Step 6: Search Indexing

Search indexing happens asynchronously via `IndexTattooJob`:
- `TattooController::create` dispatches `IndexTattooJob` immediately after creating the tattoo
- `GenerateAiTagsJob` dispatches another `IndexTattooJob` after AI tags are attached
- `IndexTattooJob` eagerly loads all relations (`tags`, `styles`, `images`, `artist`, `studio`, `primary_style`, `primary_image`), indexes the tattoo to Elasticsearch, and re-indexes the artist
- The job has 3 retries with backoff (5s, 15s, 30s)
- Tag changes via `TagController` also dispatch `IndexTattooJob` to keep the index in sync

The frontend shows a message: "Tattoo published! It will appear in search shortly."

---

## Studio Tagging and Invite Flow

During client upload, users can tag the studio where they got their tattoo. This creates discoverability for studios and can lead to studio owners claiming their profiles.

### How Studio Tagging Works

1. **Studio Autocomplete**: The upload form includes a `StudioAutocomplete` component that searches existing studios via the API and Google Places.
2. **Selecting a Studio**: When a user selects a studio:
   - If the studio already exists in our database, `studio_id` is sent with the upload
   - If found via Google Places but not in our database, the API creates an unclaimed studio record from the Google Places data (`StudioController@findOrCreateFromPlace`)
   - `attributed_studio_name` and `attributed_location` are stored on the tattoo for display
3. **Tattoo Creation**: The tattoo is created with `studio_id` linking it to the studio. This means the tattoo appears in the studio's portfolio grid on `/studios/{slug}`.

### Unclaimed Studio Pages

Studios created via Google Places are "unclaimed" (`is_claimed = false`, no `owner_id`). They have public profile pages at `/studios/{slug}` that display:
- Studio name, address, phone, website (from Google Places)
- Google Maps embed
- Portfolio grid of tattoos tagged at this studio
- SEO metadata (JSON-LD TattooParlor schema, Open Graph tags)

This creates organic search visibility for the studio, which can prompt studio owners to claim their profile.

### Studio Invite Flow

When a client uploads a tattoo and tags an unregistered artist, an invitation can be sent:

1. **Artist Invite**: If `artist_invite_email` is provided during upload, a `StudioInvitation` record is created with:
   - `tattoo_id`: The uploaded tattoo
   - `invited_by_user_id`: The uploading client
   - `artist_name`, `studio_name`, `location`, `email`
2. **Notification**: `StudioOwnerInvitationNotification` email is sent to the invite email
3. **Artist Signs Up**: When the invited artist creates an account, they can:
   - View pending tattoos attributed to them
   - Approve or reject tattoos (`TattooController@handleApproval`)
   - Claim their studio via `POST /api/studios/{id}/claim`

### Studio Claim Endpoint

`POST /api/studios/{id}/claim`
- Sets `owner_id` to the authenticated artist
- Marks studio as claimed (`is_claimed = true`)
- Artist must be the studio owner (verified via email or admin approval)

### Related Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/studios/search?q={query}` | Search studios by name |
| `POST` | `/api/studios/find-or-create` | Find existing or create from Google Place ID |
| `POST` | `/api/studios/{id}/claim` | Claim unclaimed studio |
| `POST` | `/api/studios/{id}/invite` | Send studio owner invitation |

---

## Tag Endpoints

### Get All Tags
`GET /api/tags`

### Search Tags (Autocomplete)
`GET /api/tags/search?q={query}&limit={limit}`

### Get Featured/Popular Tags
`GET /api/tags/featured?limit={limit}`

### Set Tattoo Tags (Replace All)
`POST /api/tattoos/{tattooId}/tags`
```json
{
  "tag_ids": [1, 2, 3]
}
```

### Add Single Tag
`POST /api/tattoos/{tattooId}/tags/add`
```json
{
  "tag_id": 123
}
```

---

## AI Tag Generation Details

**Model:** `gpt-4o-mini`
**Detail Level:** `low` (cost optimization)
**Max Tokens:** 50
**Temperature:** 0.3 (consistent results)

**Prompt:**
> Analyze this tattoo image and provide three to five single-word noun descriptions that best describe what you see. Do not use words like "ink" or "tattoo" as we already know this is supposed to be a tattoo. Also avoid generic words like "art" or "design" as we want descriptive nouns for the image itself. Focus on the main subjects and objects visible in the tattoo. Return only the words separated by commas, no additional text or explanation.

**Tag Matching:**
1. Exact match by slug
2. Exact match by name
3. Partial match (contains)

Unmatched AI suggestions are logged for potential admin review but not added to the tattoo.

---

## Future Enhancements

### Custom/Pending Tags
Allow users to type custom tags not in our database:
1. Add `status` field to `tags` table: `approved`, `pending`, `rejected`
2. User-submitted tags start as `pending`
3. Admin reviews and approves/rejects
4. Approved tags become available for all users
5. Show pending tags with a badge/indicator

**Database Migration:**
```php
Schema::table('tags', function (Blueprint $table) {
    $table->enum('status', ['approved', 'pending', 'rejected'])
          ->default('approved');
    $table->unsignedBigInteger('submitted_by')->nullable();
    $table->foreign('submitted_by')->references('id')->on('users');
});
```

### Pre-Upload AI Analysis
Analyze images before form submission:
1. Create temp upload endpoint
2. Return AI suggestions immediately
3. User selects from suggestions while filling form
4. More seamless UX but requires temp storage handling

### Batch Tag Generation
For existing tattoos without tags:
1. Admin endpoint to queue tattoos for tagging
2. Background job processes in batches
3. Rate limiting to manage API costs

---

## Related Files

**Backend:**
- `app/Http/Controllers/TattooController.php` - Tattoo CRUD
- `app/Http/Controllers/TagController.php` - Tag management
- `app/Services/TagService.php` - AI tag generation
- `app/Models/Tag.php` - Tag model
- `app/Models/Tattoo.php` - Tattoo model (has `tags()` relationship)
- `app/Jobs/IndexTattooJob.php` - Async ES indexing (standard for all tattoo index operations)
- `app/Jobs/GenerateAiTagsJob.php` - Async AI tag generation

**Frontend (Next.js):**
- `nextjs/components/TattooCreateWizard.tsx` - Artist upload wizard (styles, tags, placement)
- `nextjs/components/ClientUploadWizard.tsx` - Client upload wizard (simplified: images, title, description, optional artist tag)
- `nextjs/services/tattooService.ts` - `clientUpload()` for client uploads, `create()` for artist uploads
- `nextjs/components/TattooModal.tsx` - Tattoo detail view (shows "Uploaded by" comment box for client uploads)
- `nextjs/components/dashboard/PendingApprovalsCard.tsx` - Artist approval dialog

**Frontend (React Native):**
- `reactnative/app/screens/ClientUploadScreen.tsx` - Client upload wizard
- `reactnative/app/screens/UploadScreen.tsx` - Artist upload (single + bulk album upload entry point)
- `reactnative/app/screens/BulkUploadConfirmScreen.tsx` - Album upload confirm screen
- `reactnative/app/screens/DraftsScreen.tsx` - Bulk upload draft review and publish
- `reactnative/app/screens/TattooDetailScreen.tsx` - Tattoo detail view (shows "Uploaded by" comment box for client uploads)
- `reactnative/app/screens/PendingApprovalsScreen.tsx` - Artist approval screen
- `reactnative/app/components/common/StudioAutocomplete.tsx` - Studio search/select component

**Backend (Studio/Invite):**
- `app/Http/Controllers/StudioController.php` - Studio CRUD, claim, invite
- `app/Models/Studio.php` - Studio model
- `app/Models/StudioInvitation.php` - Invitation model
- `app/Notifications/StudioOwnerInvitationNotification.php` - Invitation email
- `app/Services/GooglePlacesService.php` - Creates studios from Google Places data

**Routes:**
- `routes/api.php` - API route definitions
