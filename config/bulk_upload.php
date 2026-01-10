<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Batch Size
    |--------------------------------------------------------------------------
    |
    | The maximum number of images that can be processed in a single batch.
    | This helps manage memory usage and API rate limits for AI tagging.
    |
    */
    'max_batch_size' => env('BULK_UPLOAD_MAX_BATCH_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | ZIP Expiry Days
    |--------------------------------------------------------------------------
    |
    | Number of days to keep uploaded ZIP files in S3 storage.
    | After this period, ZIPs are automatically deleted to save storage.
    |
    */
    'zip_expiry_days' => env('BULK_UPLOAD_ZIP_EXPIRY_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Maximum ZIP Size (MB)
    |--------------------------------------------------------------------------
    |
    | Maximum allowed ZIP file size in megabytes.
    | Larger files may timeout during upload.
    |
    */
    'max_zip_size_mb' => env('BULK_UPLOAD_MAX_ZIP_SIZE_MB', 500),

    /*
    |--------------------------------------------------------------------------
    | Allowed Image Extensions
    |--------------------------------------------------------------------------
    |
    | File extensions that will be extracted from ZIP files.
    | Video files and other formats are skipped.
    |
    */
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],

    /*
    |--------------------------------------------------------------------------
    | Auto-Generate AI Tags
    |--------------------------------------------------------------------------
    |
    | Whether to automatically generate AI tags during batch processing.
    | Can be disabled to save on OpenAI API costs.
    |
    */
    'auto_generate_ai_tags' => env('BULK_UPLOAD_AUTO_AI_TAGS', true),
];
