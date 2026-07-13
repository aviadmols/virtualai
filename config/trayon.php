<?php

// === CONSTANTS ===
// Default pricing knobs. These are DEFAULTS only — the DB (ai_operations.credit_multiplier)
// and the billing layer may override per operation. Never hardcode the markup at a
// call site; a call site reads config('trayon.pricing.markup_default').
// Guarded so config:cache (which evaluates config files together) is idempotent.
defined('MARKUP_DEFAULT') || define('MARKUP_DEFAULT', 2.5);        // selling price = actual_cost_usd × markup
defined('OPENING_GRANT_USD') || define('OPENING_GRANT_USD', 5);    // opening credit for a new account
defined('MEDIA_SIGNED_TTL') || define('MEDIA_SIGNED_TTL', 600);    // signed-URL lifetime (seconds)

// Low-balance warning threshold: warn the merchant when spendable credit drops
// to this many micro-USD ($1.00). A warn, not a gate — the gate is exact.
defined('CREDIT_LOW_BALANCE_MICRO_USD') || define('CREDIT_LOW_BALANCE_MICRO_USD', 1_000_000);

// Reservation lock TTL (seconds). MUST exceed the worst-case generation job
// runtime (OPENROUTER_TIMEOUT × attempts + fallback + storage) so the Redis lock
// never expires mid-generation and a second trigger never bypasses the reservation.
// OpenRouter timeout defaults to 80s; 300s leaves ample headroom for retry+fallback.
defined('CREDIT_RESERVATION_TTL') || define('CREDIT_RESERVATION_TTL', 300);

// Usage-limit defaults (per-(account,site) widget generations-per-minute, and a
// per-account ceiling across all its sites). saas-credits-billing owns these NUMBERS
// + the typed 429 shape; railway-infra owns the Redis bucket the RateLimiter uses.
// A site may override its per-site cap via sites.usage_limits.widget_rpm.
defined('USAGE_SITE_WIDGET_RPM') || define('USAGE_SITE_WIDGET_RPM', 20);     // per (account,site) gen/min
defined('USAGE_ACCOUNT_GEN_RPM') || define('USAGE_ACCOUNT_GEN_RPM', 120);    // per account gen/min (abuse ceiling)

// Canonical queue names — the locked work-type split. Mirrored in config/horizon.php.
// Jobs reference these consts, never a magic string, and every job carries account_id.
defined('Q_GENERATIONS') || define('Q_GENERATIONS', 'generations');
defined('Q_SCANS') || define('Q_SCANS', 'scans');
defined('Q_WEBHOOKS') || define('Q_WEBHOOKS', 'webhooks');
defined('Q_MEDIA') || define('Q_MEDIA', 'media');
defined('Q_BULK') || define('Q_BULK', 'bulk');
defined('Q_DEFAULT') || define('Q_DEFAULT', 'default');

return [

    // Per-site credential encryption key (separate from APP_KEY so it rotates
    // independently). The per-site credential cast uses this to encrypt widget_secret.
    'credentials_key' => env('TENANT_CREDENTIALS_KEY'),

    'pricing' => [
        'markup_default' => (float) env('CREDIT_MARKUP_DEFAULT', MARKUP_DEFAULT),
        'opening_grant_usd' => (float) env('CREDIT_OPENING_GRANT_USD', OPENING_GRANT_USD),
    ],

    // Credit-ledger / reservation knobs. The markup default lives under pricing
    // above; these are the gate + reservation parameters.
    'credits' => [
        // Warn the merchant when spendable credit drops to/below this (micro-USD).
        'low_balance_micro_usd' => (int) env('CREDIT_LOW_BALANCE_MICRO_USD', CREDIT_LOW_BALANCE_MICRO_USD),
        // Reservation lock TTL (seconds) — must exceed the generation job timeout.
        'reservation_ttl' => (int) env('CREDIT_RESERVATION_TTL', CREDIT_RESERVATION_TTL),
    ],

    // Usage limits — the UsageGate numbers (per-site + per-account RPM). The typed
    // 429 (Retry-After) is the response shape; the Redis bucket is railway-infra's.
    'usage' => [
        'site_widget_rpm' => (int) env('USAGE_SITE_WIDGET_RPM', USAGE_SITE_WIDGET_RPM),
        'account_gen_rpm' => (int) env('USAGE_ACCOUNT_GEN_RPM', USAGE_ACCOUNT_GEN_RPM),
    ],

    'media' => [
        'disk' => env('MEDIA_DISK', 's3'),
        'cdn_url' => env('MEDIA_CDN_URL'),
        'signed_ttl' => (int) env('MEDIA_SIGNED_TTL', MEDIA_SIGNED_TTL),
    ],

    // The canonical queue map. Backend dispatches onto these names by key.
    // `bulk` is the merchant-triggered mass-generation trickle (product image
    // transforms) — its own capped supervisor so a 500-image batch can never
    // starve the shopper-facing `generations` money path.
    'queues' => [
        'generations' => Q_GENERATIONS,
        'scans' => Q_SCANS,
        'webhooks' => Q_WEBHOOKS,
        'media' => Q_MEDIA,
        'bulk' => Q_BULK,
        'default' => Q_DEFAULT,
    ],
];
