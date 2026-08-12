<?php

return [
    'upload_disk' => env('COMMUNITY_MEDIA_DISK', 'public'),
    'chunk_disk' => env('COMMUNITY_CHUNK_DISK', 'local'),
    'chunk_size' => (int) env('COMMUNITY_CHUNK_SIZE', 5 * 1024 * 1024),
    'max_upload_bytes' => (int) env('COMMUNITY_MAX_UPLOAD_BYTES', 2 * 1024 * 1024 * 1024),
    'upload_ttl_hours' => (int) env('COMMUNITY_UPLOAD_TTL_HOURS', 24),
    'allowed_mimes' => [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'video/mp4', 'video/webm', 'video/quicktime',
        'application/pdf',
    ],
];
