<?php

// === CONSTANTS ===
// The media disk holds try-on inputs/outputs. It is S3-compatible (R2 or S3),
// fronted by a CDN for cheap edge reads, and exposes SHORT-lived signed URLs so a
// leaked URL can't be hot-linked for free egress. Creds come from S3_*/R2_* env.
// Guarded so config:cache (which evaluates config files together) is idempotent.
defined('MEDIA_DISK') || define('MEDIA_DISK', 's3');
defined('MEDIA_SIGNED_TTL_DEFAULT') || define('MEDIA_SIGNED_TTL_DEFAULT', 600);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Tray On media disk on a Railway persistent VOLUME (a mounted local disk).
        // Use when MEDIA_DISK=volume. The mount lives OUTSIDE public/, so bytes are
        // served by the app (MediaController) — public banners cacheable, private
        // try-on media via expiring signed URLs. MEDIA_VOLUME_PATH is the Railway
        // mount path (e.g. /upload). NOTE: a Railway Volume attaches to ONE service,
        // so generation (write) + serving (read) must run on that SAME service.
        'volume' => [
            'driver' => 'local',
            'root' => env('MEDIA_VOLUME_PATH', storage_path('app/volume-media')),
            'throw' => false,
            'report' => false,
        ],

        // Tray On media disk (S3-compatible: Cloudflare R2 or AWS S3). Reads are
        // served via MEDIA_CDN_URL (edge, cheap), not the origin bucket. R2 needs
        // a custom endpoint + path-style. Creds fall back S3_* -> R2_* -> AWS_*.
        's3' => [
            'driver' => 's3',
            'key' => env('S3_KEY', env('R2_KEY', env('AWS_ACCESS_KEY_ID'))),
            'secret' => env('S3_SECRET', env('R2_SECRET', env('AWS_SECRET_ACCESS_KEY'))),
            'region' => env('S3_REGION', env('R2_REGION', env('AWS_DEFAULT_REGION', 'auto'))),
            'bucket' => env('S3_BUCKET', env('R2_BUCKET', env('AWS_BUCKET'))),
            // Public read base = the CDN in front of the bucket (egress control).
            'url' => env('MEDIA_CDN_URL', env('S3_URL', env('R2_URL', env('AWS_URL')))),
            'endpoint' => env('S3_ENDPOINT', env('R2_ENDPOINT', env('AWS_ENDPOINT'))),
            'use_path_style_endpoint' => (bool) env('S3_USE_PATH_STYLE_ENDPOINT', env('R2_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false))),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
