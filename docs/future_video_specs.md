# Future Video Upload Support

## Overview

This document outlines the scope, infrastructure changes, and cost implications of allowing users to upload short video clips alongside images.

---

## Current Image Upload Architecture

### Three Upload Paths

1. **Direct upload** -- `POST /api/tattoos/create` with multipart files -> `ImageService::processImage()` -> S3 -> `Image` record -> dispatches `GenerateAiTagsJob` + `IndexTattooJob`
2. **Presigned URL upload** -- Client gets a presigned S3 PUT URL from `/api/uploads/presign`, uploads directly to S3, then confirms via `/api/uploads/confirm` which creates the `Image` records
3. **Bulk upload (ZIP)** -- ZIP uploaded -> scanned -> images extracted in batches -> S3 -> artist reviews -> published as tattoos

All three converge on the `images` table (filename, uri) and the `tattoos_images` pivot. Post-upload, OpenAI Vision (`gpt-4o-mini`) generates AI tags, and tattoos are indexed to Elasticsearch.

### Current Infrastructure

- **Storage**: S3 (direct access, no CDN)
- **Queues**: Redis via Horizon (5 default workers, 3 bulk-upload workers at 512MB)
- **Image Processing**: Intervention Image v3 (Imagick/GD)
- **AI Tagging**: OpenAI `gpt-4o-mini` Vision API (low detail, 50 max tokens)
- **Search**: Elasticsearch 7.17 (likely AWS OpenSearch)
- **Monitoring**: Sentry

---

## What Needs to Change

### 1. Database Schema

- **`images` table** -- Add `media_type` (image/video), `duration_seconds`, `width`, `height`, `file_size_bytes`
- **`tattoos` table** -- Add `has_video` boolean for quick filtering
- **New `video_processing` table** -- Track transcoding status (queued/processing/completed/failed), thumbnail image ID, transcoded format URLs, error messages

### 2. Validation and Upload Endpoints

- **`ImageController`** -- Presigned URL validation currently only allows `image/jpeg, image/png, image/webp, image/gif`. Add `video/mp4, video/quicktime, video/webm`
- **New constraints** -- Max file size, max duration (e.g. 30s), resolution limits
- **Presigned URL expiry** -- Currently 15 minutes; large video uploads may need longer
- **Multipart upload** -- For videos over ~100MB, S3 multipart upload support instead of a single PUT

### 3. Processing Pipeline (Biggest Lift)

- **New `VideoProcessingJob`** -- After upload, transcode via FFmpeg to standard formats (mp4/webm), generate a thumbnail frame, extract duration/dimensions. Needs significantly longer timeouts (600s+)
- **Thumbnail generation** -- Each video needs a poster image stored as a regular `Image` record
- **`ImageService`** -- Branch on media type: images go through the current path, videos go through the new pipeline
- **`WatermarkService`** -- Currently uses GD/Imagick for images; watermarking video requires FFmpeg overlay

### 4. AI Tagging

- **`TagService` / `GenerateAiTagsJob`** -- Currently sends image URLs to OpenAI Vision. For videos, extract a representative keyframe and tag that. Tagging from a thumbnail frame is the simplest starting point.

### 5. Elasticsearch and Search

- **`TattooIndexResource`** -- Include `media_type`, `duration_seconds`, thumbnail URIs
- **Search filters** -- Allow filtering by `has_video`, duration range
- **Display data** -- Search results need to differentiate image vs video cards

### 6. API Resources

- **`BriefImageResource` and related** -- Add `media_type`, `thumbnail_uri`, `duration_seconds`, processing status
- Clients need to know whether a media item is an image or video to render it correctly

### 7. Bulk Upload

- **`ScanBulkUploadZip` / `ProcessBulkUploadBatch`** -- Currently only catalog image files from ZIPs. Would need to recognize video file extensions and handle them through the video pipeline

### 8. Infrastructure

- **CloudFront** -- Required for video delivery; also benefits existing image delivery
- **FFmpeg** -- Already in Docker image but not used; needs worker scaling for transcoding
- **Horizon workers** -- Need dedicated video queue with higher memory (1-2GB) and longer timeouts

---

## Effort Estimate by Area

| Area | Files Affected | Relative Effort |
|---|---|---|
| DB migrations + Model updates | 3-4 files | Low |
| Validation and upload endpoints | 2-3 files | Low |
| Video processing pipeline (FFmpeg) | 2-3 new files | High |
| Thumbnail generation | 1-2 files | Medium |
| AI tagging adaptation | 1-2 files | Low-Medium |
| Elasticsearch indexing | 2-3 files | Medium |
| API Resources | 3-4 files | Low-Medium |
| Bulk upload support | 2-3 files | Medium |
| Infrastructure (FFmpeg, queues, CDN) | Config/DevOps | High |

---

## Cost Implications

### Storage (S3)

- A 30-second video clip is roughly 5-30MB vs images at 0.5-3MB
- Transcoding to multiple formats (mp4 + webm) and generating thumbnails means each video becomes ~2-3x its original size on disk
- **Estimate: +$50-200/month**, growing over time as content accumulates

### Bandwidth / Egress

- Currently serving images directly from S3 at $0.09/GB with no CDN
- Video streaming is dramatically more data -- a single 30s clip viewed could be 5-30MB per view
- 1,000 daily video plays at 15MB each = ~450GB/month = ~$40/month in S3 egress alone
- **CloudFront is essentially required** ($0.085/GB, but caching reduces effective cost significantly)
- New line item: **+$15-100/month** depending on traffic, but saves money on egress at scale

### Transcoding / Processing

| Option | Cost | Complexity |
|---|---|---|
| FFmpeg on existing workers | ~$0 marginal (already in Docker, needs more worker memory/time) | Medium |
| AWS Elemental MediaConvert | ~$0.0075/minute of video processed | Low |
| Third-party (Mux, Cloudinary) | $0.03-0.10/minute + hosting | Lowest |

Self-hosted FFmpeg requires beefing up Horizon: 1-2GB per worker, longer timeouts, likely a larger EC2 instance or a dedicated one: **+$50-200/month** in compute.

### AI Tagging (OpenAI)

- Extract a keyframe and tag it the same way as images -- essentially the same cost per upload
- Sampling multiple frames per video for better tags multiplies cost by 3-5x, but still cheap
- **Negligible change**

### Elasticsearch

- Video metadata (duration, media type, thumbnail URL) adds trivial index size
- **+$0-20/month**

### Cost Summary

| Component | Monthly Increase | Notes |
|---|---|---|
| S3 storage | +$50-200 | Grows over time as videos accumulate |
| CloudFront (new) | +$15-100 | Should be added regardless for images too |
| S3 egress savings (with CF) | -$20-50 | Offsets some CloudFront cost |
| Compute (bigger workers) | +$50-200 | More memory/CPU for transcoding |
| Transcoding (if managed) | +$50-300 | Only if using MediaConvert/Mux instead of self-hosted FFmpeg |
| OpenAI tagging | ~$0 | Tag from extracted keyframe |
| Elasticsearch | ~$0-20 | Trivial metadata |
| **Total** | **+$150-750/month** | Lower end if self-hosting FFmpeg, higher with managed transcoding |

The biggest variable is volume. At low volume (50-100 videos/month), costs stay at the low end. Heavy upload and viewing traffic compounds egress and storage quickly.

---

## Key Decision Points

1. **CloudFront** -- Required for video, beneficial for images. Should be prioritized.
2. **Transcoding approach** -- Self-hosted FFmpeg (cheapest, more ops burden) vs managed service (simpler, ongoing cost).
3. **Video constraints** -- Max duration (e.g. 30s), max file size, allowed formats. Tighter constraints reduce cost and complexity.
4. **Bulk upload support** -- Whether to support videos in ZIP bulk uploads or only through direct/presigned upload paths.
