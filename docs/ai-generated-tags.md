# AI Generated Tags

This document describes the AI-powered tag suggestion system for tattoo uploads.

## Overview

InkedIn uses OpenAI's Vision API (GPT-4o-mini) to analyze tattoo images and suggest relevant tags. This helps users discover appropriate tags for their uploads and improves searchability across the platform.

## Architecture

### Components

| Component | Location | Purpose |
|-----------|----------|---------|
| TagService | `app/Services/TagService.php` | Core AI analysis and tag management logic |
| TagController | `app/Http/Controllers/TagController.php` | API endpoints for tag operations |
| BlockedTerm | `app/Models/BlockedTerm.php` | Content filtering database model |
| Tag | `app/Models/Tag.php` | Tag database model with `is_ai_generated` flag |

### Database Tables

**tags**
- `is_pending` (boolean) - Whether tag needs admin approval
- `is_ai_generated` (boolean) - Whether tag was created from AI suggestion

**blocked_terms**
- `term` (string) - Blocked word/phrase
- `category` (string) - Category: explicit, profanity, hate, violence
- `is_active` (boolean) - Whether the term is actively blocked

## User Flow

### 1. Upload Flow (Real-time Suggestions)

When a user uploads tattoo images:

```
User uploads images → Images uploaded to S3 → Frontend calls POST /tags/suggest
                                                         ↓
                                              OpenAI Vision analyzes images
                                                         ↓
                                              Tags filtered through BlockedTerm
                                                         ↓
                                              Returns mix of existing + new suggestions
```

**Endpoint:** `POST /api/tags/suggest`

**Request:**
```json
{
  "image_urls": [
    "https://s3.amazonaws.com/bucket/image1.jpg",
    "https://s3.amazonaws.com/bucket/image2.jpg"
  ]
}
```

**Response:**
```json
{
  "success": true,
  "data": [
    { "id": 42, "name": "dragon", "slug": "dragon", "is_pending": false },
    { "id": null, "name": "phoenix", "slug": "phoenix", "is_new_suggestion": true }
  ]
}
```

- Tags with `id` = existing tags in database
- Tags with `id: null` and `is_new_suggestion: true` = new suggestions (not yet created)

### 2. User Accepts AI Suggestion

When a user clicks on a new AI suggestion (one that doesn't exist):

```
User clicks suggestion → Frontend calls POST /tags/create-from-ai
                                            ↓
                                  Tag created with:
                                    - is_pending = false (approved)
                                    - is_ai_generated = true
                                            ↓
                                  Tag returned to frontend
                                            ↓
                                  Tag added to user's selection
```

**Endpoint:** `POST /api/tags/create-from-ai`

**Request:**
```json
{
  "name": "phoenix"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 156,
    "name": "phoenix",
    "slug": "phoenix",
    "is_pending": false,
    "is_ai_generated": true
  }
}
```

**Key Design Decision:** AI-suggested tags that users explicitly accept are created as **approved** (`is_pending = false`). The rationale is that user acceptance serves as implicit moderation.

## OpenAI Integration

### Model Configuration

| Setting | Value | Rationale |
|---------|-------|-----------|
| Model | `gpt-4o-mini` | Cost-effective for image analysis |
| Detail | `low` | Reduces token usage while maintaining accuracy |
| Max Tokens | `50` | Only need 5 short words |
| Temperature | `0.3` | Lower for consistent, deterministic results |

### Prompt

```
Analyze this tattoo image and provide three to five single-word noun descriptions
that best describe what you see. Focus on the main subjects, objects, and themes
visible in the tattoo (e.g., animals, flowers, symbols, mythological creatures).
Do not use words like "ink" or "tattoo" as we already know this is a tattoo.
Avoid generic words like "art", "design", "style", or "piece".
IMPORTANT: Do not suggest any inappropriate, vulgar, sexual, or offensive terms.
Keep suggestions family-friendly and professional. If the image contains adult or
inappropriate content, return only safe, neutral descriptors or nothing at all.
It is okay to not return anything if the image is abstract or inappropriate.
Return only the words separated by commas, no additional text or explanation.
```

### Response Parsing

1. Split response by commas
2. Trim whitespace from each tag
3. Convert to lowercase
4. Allow multi-word tags (spaces preserved within tags)
5. Filter to 2-30 characters
6. Limit to 5 tags maximum

## Content Filtering

### Two-Layer Approach

1. **AI Prompt Instructions** - OpenAI is instructed to avoid inappropriate content
2. **Database Blocklist** - Server-side filtering using `blocked_terms` table

### BlockedTerm Model

```php
// Get cached blocked terms (1 hour TTL)
$blockedTerms = BlockedTerm::getActiveTerms();

// Check if tag contains any blocked term
foreach ($blockedTerms as $blocked) {
    if (str_contains($tagName, $blocked)) {
        return false; // Tag rejected
    }
}
```

### Adding New Blocked Terms

Via React Admin panel at `/admin#/blocked-terms` or API:

```bash
POST /api/admin/blocked-terms
{
  "term": "inappropriate",
  "category": "explicit",
  "is_active": true
}
```

Categories: `explicit`, `profanity`, `hate`, `violence`

Cache is automatically cleared when terms are added/updated/deleted.

## Tag Matching Logic

When AI suggests a tag, the system attempts to match it to existing tags:

```php
public function findMatchingTag(string $tagName): ?Tag
{
    // 1. Exact match by slug
    $tag = Tag::where('slug', Str::slug($tagName))->first();
    if ($tag) return $tag;

    // 2. Exact match by name
    $tag = Tag::where('name', $tagName)->first();
    if ($tag) return $tag;

    // 3. Partial match (existing tag contains the term)
    $tag = Tag::where('name', 'like', '%' . $tagName . '%')->first();

    return $tag;
}
```

This ensures AI suggestions like "dragons" match existing "dragon" tags when possible.

## Environment Configuration

Required in `.env`:

```
OPEN_AI_API_KEY=sk-proj-...
```

The key is loaded via `config/openai.php`:

```php
'api_key' => env('OPEN_AI_API_KEY'),
```

## Logging

All AI operations are logged for debugging and monitoring:

```php
// Successful analysis
Log::info("AI tag suggestions generated", [
    'image_count' => count($imageUrls),
    'raw_suggestions' => $uniqueTags,
    'result_tags' => [...]
]);

// Blocked content
Log::warning("Blocked inappropriate AI tag suggestion", ['tag' => $tagName]);

// Errors
Log::error("Failed to analyze image URL", [
    'url' => $imageUrl,
    'error' => $e->getMessage()
]);
```

## Admin Management

### React Admin Panel

- **Tags** (`/admin#/tags`) - View all tags, filter by `is_ai_generated`, approve/reject pending
- **Blocked Terms** (`/admin#/blocked-terms`) - Manage content filter blocklist

### Tag Approval Status

| is_pending | is_ai_generated | Status |
|------------|-----------------|--------|
| false | false | User-created, approved |
| false | true | AI-suggested, user accepted |
| true | false | User-created, pending review |
| true | true | AI-created (background job), pending review |

## Cost Considerations

- **Model:** GPT-4o-mini is significantly cheaper than GPT-4o
- **Detail Level:** Using `low` detail reduces input tokens
- **Max Tokens:** Capped at 50 to minimize output costs
- **Caching:** Consider caching suggestions for identical images (not yet implemented)

## Future Improvements

1. **Image hash caching** - Cache AI results by image hash to avoid re-analysis
2. **Batch processing** - Analyze multiple images in single API call
3. **Confidence scores** - Return AI confidence with suggestions
4. **Tag synonyms** - Better matching via synonym table
5. **Usage analytics** - Track which AI suggestions users accept/reject
