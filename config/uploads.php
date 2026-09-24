<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Image Size (MB)
    |--------------------------------------------------------------------------
    |
    | The largest single image a client may write through a direct upload.
    | The limit is signed into the upload policy, so S3 refuses anything over
    | it rather than storing it and leaving us to clean up after.
    |
    | Phone cameras set the ceiling. React Native uploads at full sensor
    | resolution, and animated GIFs skip the web client's compression step
    | entirely, so the headroom here is deliberate.
    |
    | Instagram ZIPs do not come through here. They go to /bulk-uploads and
    | have their own limit in config/bulk_upload.php.
    |
    */
    'max_image_size_mb' => env('UPLOAD_MAX_IMAGE_SIZE_MB', 25),

    /*
    |--------------------------------------------------------------------------
    | Upload Window (minutes)
    |--------------------------------------------------------------------------
    |
    | How long a signed upload policy stays valid once it is handed out.
    |
    */
    'window_minutes' => env('UPLOAD_WINDOW_MINUTES', 15),
];
