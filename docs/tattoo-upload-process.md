# Tattoo Upload Process

This document outlines the complete tattoo upload flow, including image processing, tagging, and search indexing.

## Overview

When an artist uploads a tattoo, the system:
1. Uploads and processes images
2. Creates the tattoo record with metadata
3. Attaches user-selected tags
4. Dispatches `GenerateAiTagsJob` for async AI tag generation
5. Dispatches `IndexTattooJob` for async Elasticsearch indexing
6. Returns the tattoo immediately (AI tags and search indexing happen in background)

## Upload Flow

### Step 1: Form Submission

**Endpoint:** `POST /api/tattoos/create`

**Request (multipart/form-data):**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `files` | File[] | Yes | Up to 5 image files |
| `description` | string | Yes | Tattoo description |
| `primary_style_id` | int | Yes | Primary tattoo style |
| `additional_style_ids` | JSON array | No | Additional style IDs |
| `tag_ids` | JSON array | No | User-selected tag IDs |

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

**Frontend:**
- `components/TattooCreateForm.tsx` - Upload form with AI modal
- `components/TagsAutocomplete.tsx` - Tag selection component

**Routes:**
- `routes/api.php` - API route definitions
