# Bulk Tattoo Upload Feature

## Overview

Allow artists to bulk upload images (from Instagram export or any ZIP file) and efficiently add metadata via a paginated thumbnail interface before publishing to their portfolio.

The process is:
1. **Scan** - Catalog all images in ZIP without extracting
2. **Process** - Extract and upload to S3 in batches of 200
3. **Review** - Edit metadata (placement, styles, tags) via thumbnail grid
4. **Publish** - Create tattoo records and index to Elasticsearch

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
    source VARCHAR(50) DEFAULT 'manual',  -- 'instagram', 'manual'
    status ENUM('scanning', 'cataloged', 'processing', 'ready', 'completed', 'failed') DEFAULT 'scanning',

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

    -- Original source data (from ZIP scan)
    zip_path VARCHAR(500) NOT NULL,
    file_size_bytes INT NULL,
    original_caption TEXT NULL,
    original_timestamp TIMESTAMP NULL,

    -- User-editable fields
    description TEXT NULL,
    placement_id BIGINT UNSIGNED NULL,
    primary_style_id BIGINT UNSIGNED NULL,
    additional_style_ids JSON NULL,

    -- Tags
    ai_suggested_tags JSON NULL,
    approved_tag_ids JSON NULL,

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

### Batch Processing

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
| `POST` | `/api/bulk-uploads/{id}/publish` | Publish all ready items |
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

### `PublishBulkUploadItems`

1. Get all ready items (processed, not skipped, not published)
2. Group by `post_group_id`
3. For each group/single:
   - Create Tattoo record
   - Attach images, tags, styles
   - Index to Elasticsearch
4. Mark `is_published = true`
5. Update counts

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
| `app/Jobs/CleanupExpiredBulkUploads.php` | Remove old ZIPs |

### Frontend (nextjs)

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
