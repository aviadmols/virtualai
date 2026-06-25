<?php

// === CONSTANTS ===
// The signed widget API contract (Phase 7a). The browser JS widget (Phase 7b) is built
// against these values. Guarded define()s so config:cache stays idempotent (TS-INFRA-003).
//
// Auth headers the widget sends (resolved by the widget-auth middleware):
//   X-Tray-Site-Key  : the PUBLIC site_key (also accepted as ?site_key= for the bootstrap GET).
//   Origin           : checked against the site's allowed_origins allow-list (browser-set).
//   X-Tray-Signature : OPTIONAL HMAC-SHA256(body, widget_secret) on sensitive POSTs.
//   X-Tray-Timestamp : the unix seconds the signature was computed over (replay window).
//
// The widget_secret + OpenRouter key NEVER reach the browser; the signature is computed
// server-side-issued material the host injects, and is OPTIONAL by default (off until the
// widget build wires it) so a misconfigured signature never blocks a legitimate try-on.
defined('WIDGET_API_VERSION') || define('WIDGET_API_VERSION', 'v1');
defined('WIDGET_API_PREFIX') || define('WIDGET_API_PREFIX', 'widget/v1');

defined('WIDGET_HEADER_SITE_KEY') || define('WIDGET_HEADER_SITE_KEY', 'X-Tray-Site-Key');
defined('WIDGET_HEADER_SIGNATURE') || define('WIDGET_HEADER_SIGNATURE', 'X-Tray-Signature');
defined('WIDGET_HEADER_TIMESTAMP') || define('WIDGET_HEADER_TIMESTAMP', 'X-Tray-Timestamp');
defined('WIDGET_QUERY_SITE_KEY') || define('WIDGET_QUERY_SITE_KEY', 'site_key');

// Per-account + per-site request-rate caps (requests/min) on EVERY widget route. These
// guard the whole API surface; the heavier generate path ALSO passes the UsageGate
// (generations/min) inside the controller. railway-infra owns the NUMBERS; the widget API
// reads them from config and returns a typed 429 on a hit, never a 500.
defined('WIDGET_SITE_RPM') || define('WIDGET_SITE_RPM', 60);       // per (account, site) requests/min
defined('WIDGET_ACCOUNT_RPM') || define('WIDGET_ACCOUNT_RPM', 240); // per account requests/min (abuse ceiling)

// HMAC replay window (seconds): a signed request older/newer than this is rejected.
defined('WIDGET_HMAC_MAX_SKEW') || define('WIDGET_HMAC_MAX_SKEW', 300);

// Gallery: how many of the end-user's session generations the slider returns, capped.
defined('WIDGET_GALLERY_PER_PAGE') || define('WIDGET_GALLERY_PER_PAGE', 12);
defined('WIDGET_GALLERY_MAX_PER_PAGE') || define('WIDGET_GALLERY_MAX_PER_PAGE', 50);

return [

    'version' => WIDGET_API_VERSION,
    'prefix' => WIDGET_API_PREFIX,

    // Header / query names the middleware reads.
    'headers' => [
        'site_key' => WIDGET_HEADER_SITE_KEY,
        'signature' => WIDGET_HEADER_SIGNATURE,
        'timestamp' => WIDGET_HEADER_TIMESTAMP,
    ],
    'query_site_key' => WIDGET_QUERY_SITE_KEY,

    // Request-rate caps (requests/min). railway-infra owns these numbers.
    'rate' => [
        'site_rpm' => (int) env('WIDGET_SITE_RPM', WIDGET_SITE_RPM),
        'account_rpm' => (int) env('WIDGET_ACCOUNT_RPM', WIDGET_ACCOUNT_RPM),
    ],

    // HMAC verification. Disabled by default: the auth floor is site_key + Origin
    // allow-list; HMAC is an OPTIONAL extra on sensitive POSTs the host can enable.
    // When enabled, a missing/invalid signature is a typed 403, never a 500.
    'hmac' => [
        'enabled' => (bool) env('WIDGET_HMAC_ENABLED', false),
        'max_skew' => (int) env('WIDGET_HMAC_MAX_SKEW', WIDGET_HMAC_MAX_SKEW),
    ],

    'gallery' => [
        'per_page' => WIDGET_GALLERY_PER_PAGE,
        'max_per_page' => WIDGET_GALLERY_MAX_PER_PAGE,
    ],
];
