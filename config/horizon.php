<?php

use Illuminate\Support\Str;

// === CONSTANTS ===
// Queue names — the locked work-type split (ARCHITECTURE.md). Queues are split by
// WORK TYPE, never by tenant; every job carries its own account_id. Constants are
// guarded so config:cache (which evaluates config files together) is idempotent.
defined('QUEUE_GENERATIONS') || define('QUEUE_GENERATIONS', 'generations'); // heavy/bursty AI image jobs (10-60s)
defined('QUEUE_SCANS') || define('QUEUE_SCANS', 'scans');                   // PDP fetch + AI extraction
defined('QUEUE_WEBHOOKS') || define('QUEUE_WEBHOOKS', 'webhooks');          // tiny, latency-sensitive callbacks
defined('QUEUE_MEDIA') || define('QUEUE_MEDIA', 'media');                   // image moves + retention deletes
defined('QUEUE_BULK') || define('QUEUE_BULK', 'bulk');                      // merchant mass-generation trickle
defined('QUEUE_DEFAULT') || define('QUEUE_DEFAULT', 'default');             // mail, exports, housekeeping

// Supervisor keys.
defined('SUP_GENERATIONS') || define('SUP_GENERATIONS', 'supervisor-generations');
defined('SUP_SCANS') || define('SUP_SCANS', 'supervisor-scans');
defined('SUP_WEBHOOKS') || define('SUP_WEBHOOKS', 'supervisor-webhooks');
defined('SUP_MEDIA') || define('SUP_MEDIA', 'supervisor-media');
defined('SUP_BULK') || define('SUP_BULK', 'supervisor-bulk');
defined('SUP_DEFAULT') || define('SUP_DEFAULT', 'supervisor-default');

// Balance strategy: within a supervisor, shift processes toward its busiest queue.
defined('BALANCE_STRATEGY') || define('BALANCE_STRATEGY', 'auto');

// Per-job timeouts (seconds). A generation can run 10-60s; webhooks are fast.
defined('GEN_TIMEOUT') || define('GEN_TIMEOUT', 70);
defined('SCAN_TIMEOUT') || define('SCAN_TIMEOUT', 60);
defined('WEBHOOK_TIMEOUT') || define('WEBHOOK_TIMEOUT', 30);
defined('MEDIA_TIMEOUT') || define('MEDIA_TIMEOUT', 120);
defined('BULK_TIMEOUT') || define('BULK_TIMEOUT', 90);
defined('DEFAULT_TIMEOUT') || define('DEFAULT_TIMEOUT', 60);

// Per-process memory ceilings (MB). base64/image payloads make generations the OOM risk.
defined('GEN_MEMORY') || define('GEN_MEMORY', 512);
defined('SCAN_MEMORY') || define('SCAN_MEMORY', 384);
defined('WEBHOOK_MEMORY') || define('WEBHOOK_MEMORY', 192);
defined('MEDIA_MEMORY') || define('MEDIA_MEMORY', 384);
defined('BULK_MEMORY') || define('BULK_MEMORY', 384);
defined('DEFAULT_MEMORY') || define('DEFAULT_MEMORY', 256);

// Process caps. generations is ISOLATED and CAPPED so a burst can never eat every
// process and starve webhooks/scans. Scale beyond this by adding worker replicas.
defined('GEN_MIN_PROCS') || define('GEN_MIN_PROCS', 2);
defined('GEN_MAX_PROCS') || define('GEN_MAX_PROCS', 8);
defined('SCAN_MIN_PROCS') || define('SCAN_MIN_PROCS', 1);
defined('SCAN_MAX_PROCS') || define('SCAN_MAX_PROCS', 4);
defined('WEBHOOK_MIN_PROCS') || define('WEBHOOK_MIN_PROCS', 2);
defined('WEBHOOK_MAX_PROCS') || define('WEBHOOK_MAX_PROCS', 10);
defined('MEDIA_MIN_PROCS') || define('MEDIA_MIN_PROCS', 1);
defined('MEDIA_MAX_PROCS') || define('MEDIA_MAX_PROCS', 4);
// bulk stays a background TRICKLE by design: a 500-image merchant batch must
// never compete with shopper try-ons for provider throughput or processes.
defined('BULK_MIN_PROCS') || define('BULK_MIN_PROCS', 1);
defined('BULK_MAX_PROCS') || define('BULK_MAX_PROCS', 2);
defined('DEFAULT_MIN_PROCS') || define('DEFAULT_MIN_PROCS', 1);
defined('DEFAULT_MAX_PROCS') || define('DEFAULT_MAX_PROCS', 4);

// A failed generation is NOT blindly retried (would risk a double OpenRouter spend);
// it releases the reservation and is re-dispatched as a modeled attempt by the backend.
defined('GEN_TRIES') || define('GEN_TRIES', 1);
defined('SCAN_TRIES') || define('SCAN_TRIES', 3);
defined('WEBHOOK_TRIES') || define('WEBHOOK_TRIES', 5);
defined('MEDIA_TRIES') || define('MEDIA_TRIES', 3);
// Like generations: a bulk money-path job is never blindly retried — the async
// submit→poll lifecycle owns its own safe retries (poll re-dispatch, never re-submit).
defined('BULK_TRIES') || define('BULK_TRIES', 1);
defined('DEFAULT_TRIES') || define('DEFAULT_TRIES', 3);

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | Namespaces Horizon's Redis keys so multiple apps can share one Redis
    | instance without collisions.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | The "long wait" thresholds (seconds) that turn the dashboard metric red.
    | generations is allowed a longer wait than webhooks, which must stay fast.
    |
    */

    'waits' => [
        'redis:'.QUEUE_GENERATIONS => 60,
        'redis:'.QUEUE_SCANS => 60,
        'redis:'.QUEUE_WEBHOOKS => 10,
        'redis:'.QUEUE_MEDIA => 120,
        'redis:'.QUEUE_BULK => 600, // a deep merchant batch queue is EXPECTED, not an incident
        'redis:'.QUEUE_DEFAULT => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | The master Horizon process memory ceiling.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Five isolated supervisors, one per work-type queue. The cardinal rule: a
    | burst of `generations` must never starve `webhooks` or `scans`. Each queue
    | gets its OWN supervisor with its OWN process headroom, so a generation
    | burst is capped at GEN_MAX_PROCS and cannot consume webhook/scan capacity.
    |
    */

    'defaults' => [
        SUP_GENERATIONS => [
            'connection' => 'redis',
            'queue' => [QUEUE_GENERATIONS],
            'balance' => BALANCE_STRATEGY,
            'autoScalingStrategy' => 'time',
            'minProcesses' => GEN_MIN_PROCS,
            'maxProcesses' => GEN_MAX_PROCS,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => GEN_MEMORY,
            'tries' => GEN_TRIES,
            'timeout' => GEN_TIMEOUT,
            'nice' => 0,
        ],

        SUP_SCANS => [
            'connection' => 'redis',
            'queue' => [QUEUE_SCANS],
            'balance' => BALANCE_STRATEGY,
            'autoScalingStrategy' => 'time',
            'minProcesses' => SCAN_MIN_PROCS,
            'maxProcesses' => SCAN_MAX_PROCS,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => SCAN_MEMORY,
            'tries' => SCAN_TRIES,
            'timeout' => SCAN_TIMEOUT,
            'nice' => 0,
        ],

        SUP_WEBHOOKS => [
            'connection' => 'redis',
            'queue' => [QUEUE_WEBHOOKS],
            'balance' => BALANCE_STRATEGY,
            'autoScalingStrategy' => 'time',
            'minProcesses' => WEBHOOK_MIN_PROCS,
            'maxProcesses' => WEBHOOK_MAX_PROCS,
            'balanceMaxShift' => 2,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => WEBHOOK_MEMORY,
            'tries' => WEBHOOK_TRIES,
            'timeout' => WEBHOOK_TIMEOUT,
            'nice' => 0,
        ],

        SUP_MEDIA => [
            'connection' => 'redis',
            'queue' => [QUEUE_MEDIA],
            'balance' => BALANCE_STRATEGY,
            'autoScalingStrategy' => 'time',
            'minProcesses' => MEDIA_MIN_PROCS,
            'maxProcesses' => MEDIA_MAX_PROCS,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => MEDIA_MEMORY,
            'tries' => MEDIA_TRIES,
            'timeout' => MEDIA_TIMEOUT,
            'nice' => 0,
        ],

        SUP_BULK => [
            'connection' => 'redis',
            'queue' => [QUEUE_BULK],
            'balance' => BALANCE_STRATEGY,
            'autoScalingStrategy' => 'time',
            'minProcesses' => BULK_MIN_PROCS,
            'maxProcesses' => BULK_MAX_PROCS,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => BULK_MEMORY,
            'tries' => BULK_TRIES,
            'timeout' => BULK_TIMEOUT,
            'nice' => 10, // deprioritized on the CPU too — bulk is the background citizen
        ],

        SUP_DEFAULT => [
            'connection' => 'redis',
            'queue' => [QUEUE_DEFAULT],
            'balance' => BALANCE_STRATEGY,
            'autoScalingStrategy' => 'time',
            'minProcesses' => DEFAULT_MIN_PROCS,
            'maxProcesses' => DEFAULT_MAX_PROCS,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => DEFAULT_MEMORY,
            'tries' => DEFAULT_TRIES,
            'timeout' => DEFAULT_TIMEOUT,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            SUP_GENERATIONS => [
                'minProcesses' => GEN_MIN_PROCS,
                'maxProcesses' => GEN_MAX_PROCS,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            SUP_SCANS => [
                'minProcesses' => SCAN_MIN_PROCS,
                'maxProcesses' => SCAN_MAX_PROCS,
            ],
            SUP_WEBHOOKS => [
                'minProcesses' => WEBHOOK_MIN_PROCS,
                'maxProcesses' => WEBHOOK_MAX_PROCS,
            ],
            SUP_MEDIA => [
                'minProcesses' => MEDIA_MIN_PROCS,
                'maxProcesses' => MEDIA_MAX_PROCS,
            ],
            SUP_BULK => [
                'minProcesses' => BULK_MIN_PROCS,
                'maxProcesses' => BULK_MAX_PROCS,
            ],
            SUP_DEFAULT => [
                'minProcesses' => DEFAULT_MIN_PROCS,
                'maxProcesses' => DEFAULT_MAX_PROCS,
            ],
        ],

        'local' => [
            SUP_GENERATIONS => [
                'maxProcesses' => 2,
            ],
            SUP_SCANS => [
                'maxProcesses' => 1,
            ],
            SUP_WEBHOOKS => [
                'maxProcesses' => 2,
            ],
            SUP_MEDIA => [
                'maxProcesses' => 1,
            ],
            SUP_BULK => [
                'maxProcesses' => 1,
            ],
            SUP_DEFAULT => [
                'maxProcesses' => 1,
            ],
        ],

        // Lean profile for APP_ENV=staging — without this key a `php artisan horizon`
        // worker would have NO supervisors in staging and process nothing. Kept small so
        // a single modest worker (or the in-container fallback) stays within memory.
        'staging' => [
            SUP_GENERATIONS => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            SUP_SCANS => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            SUP_WEBHOOKS => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            SUP_MEDIA => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            SUP_BULK => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
            SUP_DEFAULT => [
                'minProcesses' => 1,
                'maxProcesses' => 1,
            ],
        ],
    ],
];
