# Bulk Tattoo Upload Feature

## Overview

Allow artists to bulk upload images and efficiently add metadata before publishing to their portfolio.

### Upload Methods

**1. ZIP Upload (Web - Next.js)**
Upload a ZIP file (Instagram export or manual collection) via the web dashboard:
1. **Scan** - Catalog all images in ZIP without extracting
2. **Process** - Extract and upload to S3 in batches of 200
3. **Review** - Edit metadata (placement, styles, tags) via thumbnail grid
4. **Publish** - Create tattoo records and index to Elasticsearch

**2. Album Upload (Mobile - React Native)**
Select multiple photos from camera roll for quick portfolio import:
1. **Select** - Pick up to 50 photos from device camera roll (multi-select)
2. **Confirm** - Preview grid with optional AI tag/style toggle
3. **Upload** - Images uploaded to S3, bulk upload created with `source: 'album'`
4. **AI Processing** (optional) - Background job suggests tags and styles per image
5. **Review** - Edit metadata via Drafts screen (accessible from dashboard)
6. **Publish** - Create tattoo records and index to Elasticsearch

---

## Instagram Data Export Format

Instagram's data export provides:

**Folder Structure:**
```
your_instagram_data/
├── media/
│   └── posts/
│       └── *.jpg, *.mp4
├── content/
│   └── posts_1.json
```

**JSON Structure (posts_1.json):**
```json
[
  {
    "media": [
      {
        "uri": "media/posts/123456_1.jpg",
        "creation_timestamp": 1609459200,
        "title": "Caption text here #hashtags"
      },
      {
        "uri": "media/posts/123456_2.jpg",
        "creation_timestamp": 1609459200
      }
    ]
  }
]
```

Multiple items in the `media` array = carousel post (multiple images, one caption).

---

## Database Schema

### `bulk_uploads` Table

Tracks each bulk upload session.

```sql
CREATE TABLE bulk_uploads (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(50) DEFAULT 'manual',  -- 'instagram', 'manual', 'album'
    status ENUM('scanning', 'cataloged', 'processing', 'ready', 'completed', 'failed', 'deleting', 'incomplete') DEFAULT 'scanning',

    -- Counts
    total_images INT DEFAULT 0,
    cataloged_images INT DEFAULT 0,
    processed_images INT DEFAULT 0,
    published_images INT DEFAULT 0,

    -- ZIP storage
    zip_filename VARCHAR(255) NULL,
    zip_size_bytes BIGINT NULL,
    zip_expires_at TIMESTAMP NULL,

    original_filename VARCHAR(255) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,

    FOREIGN KEY (artist_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (artist_id, status)
);
```

### `bulk_upload_items` Table

Individual images within a bulk upload.

```sql
CREATE TABLE bulk_upload_items (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    bulk_upload_id BIGINT UNSIGNED NOT NULL,

    -- Grouping for carousel posts
    post_group_id VARCHAR(100) NULL,
    is_primary_in_group BOOLEAN DEFAULT TRUE,

    -- Processing state
    is_cataloged BOOLEAN DEFAULT TRUE,
    is_processed BOOLEAN DEFAULT FALSE,
    is_published BOOLEAN DEFAULT FALSE,
    is_skipped BOOLEAN DEFAULT FALSE,

    -- References (populated as we progress)
    image_id BIGINT UNSIGNED NULL,
    tattoo_id BIGINT UNSIGNED NULL,

    -- Original source data (from ZIP scan, nullable for album uploads)
    zip_path VARCHAR(500) NULL,
    file_size_bytes INT NULL,
    original_caption TEXT NULL,
    original_timestamp TIMESTAMP NULL,

    -- User-editable fields
    description TEXT NULL,
    placement_id BIGINT UNSIGNED NULL,
    primary_style_id BIGINT UNSIGNED NULL,
    additional_style_ids JSON NULL,

    -- Tags and Styles
    ai_suggested_tags JSON NULL,
    ai_suggested_styles JSON NULL,
    approved_tag_ids JSON NULL,
    title VARCHAR(255) NULL,
    is_edited BOOLEAN DEFAULT FALSE,
    is_ready_for_publish BOOLEAN DEFAULT FALSE,

    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (bulk_upload_id) REFERENCES bulk_uploads(id) ON DELETE CASCADE,
    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE SET NULL,
    FOREIGN KEY (tattoo_id) REFERENCES tattoos(id) ON DELETE SET NULL,
    FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE SET NULL,
    FOREIGN KEY (primary_style_id) REFERENCES styles(id) ON DELETE SET NULL,

    INDEX (bulk_upload_id, is_processed),
    INDEX (bulk_upload_id, is_published),
    INDEX (bulk_upload_id, post_group_id)
);
```

---

## User Flow

### Album Upload Flow (React Native)

#### Step 1: Select Photos
Artist taps "Bulk Upload from Album" on the Upload screen (step 0). Opens multi-select photo picker (`ImageCropPicker.openPicker({ multiple: true, maxFiles: 50 })`).

#### Step 2: Confirm Screen (`BulkUploadConfirmScreen`)
- Grid preview of selected photos (3-4 column thumbnails)
- Count header: "X photos selected"
- Toggle: "Let AI suggest styles & tags" (default on)
- "Upload All" button

#### Step 3: Upload
Calls `POST /api/bulk-uploads/album` with `image_ids[]` (images pre-uploaded via presigned S3 URLs) and optional `ai_tag` boolean.

#### Step 4: AI Processing (if enabled)
`ProcessAlbumUploadAiJob` runs in background:
- For each item: `TagService::suggestTagsForImages()` and `StyleService::suggestStylesForImages()`
- Stores suggestions in `ai_suggested_tags` and `ai_suggested_styles` JSON columns
- Updates bulk upload status to `ready`
- Sends `BulkUploadReadyNotification` push notification: "Your X photos are ready to review"

#### Step 5: Drafts Review (`DraftsScreen`)
- Accessible from artist dashboard (shows "X Drafts" chip)
- Grid of uploaded thumbnails
- Tap item to edit: title, description, placement, primary style, tags
- AI-suggested tags/styles shown as approve/dismiss chips
- Can create new tags inline (same as single upload)

#### Step 6: Publish
- "Publish Ready" publishes items with required details (style + placement)
- "Publish All" publishes all unpublished, non-skipped items regardless of detail completeness
- Uses `PublishBulkUploadItems` job (same as ZIP flow)
- Items are optimistically removed from the UI

---

### ZIP Upload Flow (Next.js)

### Step 1: Upload Page (`/dashboard/bulk-upload`)

1. Artist uploads ZIP file (Instagram export or manual)
2. System scans ZIP, catalogs all images
3. Shows total count and batch options

### Step 2: Batch Processing

```
┌─────────────────────────────────────────────────────────────┐
│  Instagram Import - 847 images found                        │
├─────────────────────────────────────────────────────────────┤
│  ● 200 processed (ready for review)                         │
│  ○ 647 remaining                                            │
│                                                             │
│  [Process Next 200]    [View Ready Images]                  │
│                                                             │
│  Or select specific range:                                  │
│  Images [201] to [400]  [Process Selected]                  │
└─────────────────────────────────────────────────────────────┘
```

### Step 3: Review Page (`/dashboard/bulk-upload/[id]/review`)

Paginated thumbnail grid:
- Single images shown individually
- Carousel posts shown grouped with indicator
- Click to open detail modal

### Step 4: Detail Modal

```
┌────────────────────────────────────────────────────────────┐
│  Image Details                                    [×]       │
├────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  Description:                            │
│  │              │  [________________________]              │
│  │   [IMAGE]    │                                          │
│  │   1 of 3     │  Placement:                              │
│  │  ◀ ● ● ● ▶   │  [▼ Select placement    ]                │
│  └──────────────┘                                          │
│                    Primary Style:                          │
│                    [▼ Traditional         ]                │
│                                                            │
│                    AI Suggested Tags:                      │
│                    [☑ rose] [☑ skull] [☐ flower]           │
│                                                            │
│                    [ ] Skip this image                     │
│                                                            │
│                    [Save & Next →]  [Save]                 │
└────────────────────────────────────────────────────────────┘
```

### Step 5: Publish

"Publish All Ready" creates Tattoo records in background job.

---

## API Endpoints

### Bulk Upload Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/bulk-uploads` | Create session & upload ZIP |
| `GET` | `/api/bulk-uploads` | List artist's bulk uploads |
| `GET` | `/api/bulk-uploads/{id}` | Get upload status and counts |
| `DELETE` | `/api/bulk-uploads/{id}` | Cancel and cleanup |

### Album Upload (Mobile)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/bulk-uploads/album` | Create album upload from image IDs |
| `GET` | `/api/bulk-uploads/draft-count` | Get total unpublished draft count |

### Batch Processing (ZIP only)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/bulk-uploads/{id}/process-batch` | Process next N items |
| `POST` | `/api/bulk-uploads/{id}/process-range` | Process specific range |
| `GET` | `/api/bulk-uploads/{id}/process-status` | Check processing progress |

### Items Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/bulk-uploads/{id}/items` | List items (paginated, filterable) |
| `PUT` | `/api/bulk-uploads/{id}/items/{itemId}` | Update item metadata |
| `PUT` | `/api/bulk-uploads/{id}/items/batch` | Batch update items |
| `POST` | `/api/bulk-uploads/{id}/items/{itemId}/skip` | Mark item as skipped |
| `POST` | `/api/bulk-uploads/{id}/items/ai-tags` | Generate AI tags for items |

### Publishing

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/bulk-uploads/{id}/publish` | Publish items with complete details |
| `POST` | `/api/bulk-uploads/{id}/publish-all` | Publish all unpublished items (no detail requirement) |
| `GET` | `/api/bulk-uploads/{id}/publish-status` | Check publish progress |

---

## Background Jobs

### `ScanBulkUploadZip`

1. Open ZIP without full extraction
2. Detect format (Instagram vs manual)
3. Parse Instagram JSON for captions/timestamps
4. Create `bulk_upload_items` for each image
5. Group carousel posts by `post_group_id`
6. Store ZIP to S3 for later extraction
7. Update status to 'cataloged'

### `ProcessBulkUploadBatch`

1. Get next N unprocessed items (default 200)
2. Extract those files from ZIP
3. Upload to S3, create Image records
4. Generate AI tags via OpenAI
5. Mark `is_processed = true`
6. Update counts

### `ProcessAlbumUploadAiJob`

1. Takes `bulkUploadId`
2. For each item with an image:
   - `TagService::suggestTagsForImages([imageUrl])` → store in `ai_suggested_tags`
   - `StyleService::suggestStylesForImages([imageUrl])` → store in `ai_suggested_styles`
3. Update bulk upload status to `ready`
4. Send `BulkUploadReadyNotification` push notification to artist

### `PublishBulkUploadItems`

Supports two modes via `$publishAll` flag:
- **Default (`publishAll: false`)**: Publishes items with `primary_style_id` AND `placement_id` set (uses `readyItems()` scope)
- **Publish All (`publishAll: true`)**: Publishes all unpublished, non-skipped items regardless of details (uses `unpublishedItems()` scope)

Steps:
1. Get items based on mode
2. Group by `post_group_id`
3. For each group/single:
   - Create Tattoo record inside a DB transaction (includes `studio_id` from artist's primary studio)
   - Attach images, tags, styles
   - Mark `is_published = true`
4. After all items are published, batch index to Elasticsearch:
   - Loads all created tattoos with relations in a single query
   - Calls collection `searchable()` for efficient bulk indexing
   - Re-indexes the artist once
   - On batch failure, falls back to dispatching individual `IndexTattooJob` per tattoo
5. Update counts and mark bulk upload as completed

---

## Carousel Post Handling

| Field | Purpose |
|-------|---------|
| `post_group_id` | Links images from same Instagram post |
| `is_primary_in_group` | First image becomes tattoo's primary |

**Behavior:**
- Single image: `post_group_id = null`
- Carousel: All images share same `post_group_id`
- On publish: One Tattoo created with multiple images
- User can split/regroup in UI if desired

---

## ZIP Storage

- Path: `bulk-uploads/{artist_id}/{upload_id}.zip`
- Expiration: 30 days from upload
- Cleanup job removes expired ZIPs
- User can delete early when done

---

## Files to Create

### Backend (ink-api)

| File | Purpose |
|------|---------|
| `database/migrations/..._create_bulk_uploads_table.php` | Schema |
| `database/migrations/..._create_bulk_upload_items_table.php` | Schema |
| `app/Models/BulkUpload.php` | Model |
| `app/Models/BulkUploadItem.php` | Model |
| `app/Http/Controllers/BulkUploadController.php` | API |
| `app/Http/Resources/BulkUploadResource.php` | Response formatting |
| `app/Http/Resources/BulkUploadItemResource.php` | Response formatting |
| `app/Services/BulkUploadService.php` | Business logic |
| `app/Services/InstagramExportParser.php` | Parse Instagram format |
| `app/Jobs/ScanBulkUploadZip.php` | Catalog ZIP contents |
| `app/Jobs/ProcessBulkUploadBatch.php` | Extract & upload batch |
| `app/Jobs/PublishBulkUploadItems.php` | Create tattoos |
| `app/Jobs/ProcessAlbumUploadAiJob.php` | AI tag/style suggestions for album uploads |
| `app/Jobs/CleanupExpiredBulkUploads.php` | Remove old ZIPs |
| `app/Notifications/BulkUploadReadyNotification.php` | Push notification when AI processing completes |

### Frontend (Next.js - Web)

| File | Purpose |
|------|---------|
| `pages/dashboard/bulk-upload/index.tsx` | Upload page |
| `pages/dashboard/bulk-upload/[id].tsx` | Review page |
| `components/BulkUpload/UploadDropzone.tsx` | ZIP upload UI |
| `components/BulkUpload/BatchProgress.tsx` | Processing status |
| `components/BulkUpload/ThumbnailGrid.tsx` | Image grid |
| `components/BulkUpload/ItemDetailModal.tsx` | Edit modal |
| `components/BulkUpload/CarouselPreview.tsx` | Grouped images |
| `hooks/useBulkUpload.ts` | API hooks |
| `services/bulkUploadService.ts` | Service layer for bulk upload API calls |

### Frontend (React Native - Mobile)

| File | Purpose |
|------|---------|
| `app/screens/UploadScreen.tsx` | Upload entry point (includes "Bulk Upload from Album" button) |
| `app/screens/BulkUploadConfirmScreen.tsx` | Photo preview grid + AI toggle + upload button |
| `app/screens/DraftsScreen.tsx` | Draft review grid with edit modal and publish actions |
| `app/components/artist/ArtistOwnerDashboard.tsx` | Dashboard with draft count chip |
| `shared/services/bulkUploadService.ts` | Shared service layer (cross-platform) |
| `app/navigation/UploadStack.tsx` | Upload navigation (includes BulkUploadConfirm) |
| `app/navigation/ProfileStack.tsx` | Profile navigation (includes Drafts) |

---

## Configuration

Add to `.env`:
```
BULK_UPLOAD_MAX_BATCH_SIZE=200
BULK_UPLOAD_ZIP_EXPIRY_DAYS=30
BULK_UPLOAD_MAX_ZIP_SIZE_MB=500
```

Add to `config/bulk_upload.php`:
```php
return [
    'max_batch_size' => env('BULK_UPLOAD_MAX_BATCH_SIZE', 200),
    'zip_expiry_days' => env('BULK_UPLOAD_ZIP_EXPIRY_DAYS', 30),
    'max_zip_size_mb' => env('BULK_UPLOAD_MAX_ZIP_SIZE_MB', 500),
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
];
```

---

## Implementation Order

1. Database migrations & models
2. ScanBulkUploadZip job
3. BulkUploadController (create, list, items)
4. ProcessBulkUploadBatch job
5. Frontend upload page
6. Frontend review page with thumbnail grid
7. Item detail modal
8. AI tag generation integration
9. PublishBulkUploadItems job
10. Cleanup job & scheduler
