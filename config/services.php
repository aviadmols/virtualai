<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // OpenRouter — the platform AI provider (scan extraction + image generation).
    // Key is server-side only and never reaches the browser. Timeout must comfortably
    // fit the slowest image generation (see config/horizon.php GEN_TIMEOUT).
    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 80),
        'http_referer' => env('OPENROUTER_HTTP_REFERER', env('APP_URL')),
        'app_title' => env('OPENROUTER_APP_TITLE', env('APP_NAME', 'Tray On')),
    ],

    // BytePlus / Seedream — the SECOND try-on image provider (image-to-image). Key is
    // server-side only. base_url is the ModelArk region host — verify the region
    // (ap-southeast vs eu-west) matches the account before enabling a Seedream model.
    // probe_model is used ONLY by the no-spend Test-connection button.
    'byteplus' => [
        'api_key' => env('BYTEPLUS_API_KEY'),
        'base_url' => env('BYTEPLUS_BASE_URL', 'https://ark.ap-southeast.bytepluses.com/api/v3'),
        'timeout' => (int) env('BYTEPLUS_TIMEOUT', 80),
        'probe_model' => env('BYTEPLUS_PROBE_MODEL', 'seedream-4-0-250828'),
    ],

    // PayPlus — the LOCKED credit-purchase rail for v1 (behind CreditPaymentProvider).
    // secret_key is BOTH the request auth header and the webhook-signature HMAC key (the
    // `hash` header = base64(HMAC-SHA256(rawBody, secret_key))). webhook_secret is kept
    // for a future PayPlus account that signs callbacks with a distinct secret.
    'payplus' => [
        'api_key' => env('PAYPLUS_API_KEY'),
        'secret_key' => env('PAYPLUS_SECRET_KEY'),
        'page_uid' => env('PAYPLUS_PAGE_UID'),
        'base_url' => env('PAYPLUS_BASE_URL', 'https://restapi.payplus.co.il/api/v1.0'),
        'webhook_secret' => env('PAYPLUS_WEBHOOK_SECRET'),
        // Charge currency (USD default; ILS is the Q1-open decision with Aviad).
        'currency' => env('PAYPLUS_CURRENCY', 'USD'),
        'timeout' => (int) env('PAYPLUS_TIMEOUT', 30),
    ],

    // PDP fetch/render — locked by pdp-scanner (Phase 4). HTTP-first, then a
    // headless render sidecar (railway-infra hosts it; SCRAPER_SERVICE_URL points
    // at it). Tests never hit the network; these are production knobs only.
    'scraper' => [
        // 'http' (default) or 'headless' as the FIRST attempt. http-first always
        // escalates to headless on a low-density / SPA page when render is enabled.
        'driver' => env('SCRAPER_DRIVER', 'http'),

        // The headless render sidecar (Playwright/Chromium) endpoint + secret.
        // Null => HTTP-fetch-only; SPA PDPs then fail with a clear merchant reason.
        'service_url' => env('SCRAPER_SERVICE_URL'),
        'service_token' => env('SCRAPER_SERVICE_TOKEN'),

        // Feature-flag the headless path (degrade to HTTP-only if the box is down).
        'render_enabled' => (bool) env('SCRAPER_RENDER_ENABLED', false),

        // Honest, identifying user-agent for BOTH the HTTP and headless fetches.
        'user_agent' => env('SCRAPER_USER_AGENT', 'TrayOnBot/1.0 (+https://trayon.app/bot)'),

        // Bounded timeouts (seconds) for the two attempts.
        'http_timeout' => (int) env('SCRAPER_HTTP_TIMEOUT', 15),
        'render_timeout' => (int) env('SCRAPER_RENDER_TIMEOUT', 30),

        // Cap a fetched page so a giant page can never OOM the worker.
        'max_bytes' => (int) env('SCRAPER_MAX_BYTES', 3_145_728), // 3 MiB

        // Respect robots.txt by default; a documented kill-switch only for a
        // merchant's OWN verified domain.
        'respect_robots' => (bool) env('SCRAPER_RESPECT_ROBOTS', true),

        // Bounded redirect chain.
        'max_redirects' => (int) env('SCRAPER_MAX_REDIRECTS', 5),

        // Legacy keys kept for back-compat with the Phase-2 stub.
        'base_url' => env('SCRAPER_BASE_URL'),
        'api_key' => env('SCRAPER_API_KEY'),
        'timeout' => (int) env('SCRAPER_TIMEOUT', 30),
    ],

];
