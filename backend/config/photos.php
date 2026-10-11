<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | max_upload_kb is in kilobytes (51200 KB = 50 MB). Keep the PHP and Nginx
    | limits above this value, otherwise the request never reaches Laravel.
    |
    */

    'max_upload_kb' => (int) env('PHOTOS_MAX_UPLOAD_KB', 51200),

    // Checked against the file content (finfo), never against the extension.
    // HEIC/HEIF is not decodable by GD, so it is intentionally not listed.
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    // Width * height limit, checked before decoding (decompression bomb guard).
    'max_pixels' => (int) env('PHOTOS_MAX_PIXELS', 60_000_000),

    // Uploads allowed per IP address per hour.
    'upload_rate_limit' => (int) env('PHOTOS_UPLOAD_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Derived images
    |--------------------------------------------------------------------------
    */

    'web' => [
        'max_edge' => 1600,
        'quality' => 85,
    ],

    'thumb' => [
        'size' => 400,
        'quality' => 85,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gallery and polling
    |--------------------------------------------------------------------------
    */

    'per_page' => 24,

    'updates_limit' => 100,

    // Photos stuck in "pending" longer than this are marked as failed.
    'stale_pending_minutes' => 60,

];
