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
        // Region host. The ModelArk account is in ASIA PACIFIC (ap-southeast), and keys are
        // region-bound — an ap-southeast key sent to eu-west is rejected (401). So ap-southeast
        // is the default; override BYTEPLUS_BASE_URL for another region. Per-model overrides
        // live on the Models page (ai_models.base_url).
        'base_url' => env('BYTEPLUS_BASE_URL', 'https://ark.ap-southeast.bytepluses.com/api/v3'),
        'timeout' => (int) env('BYTEPLUS_TIMEOUT', 80),
        // Probe model for the Settings "Test connection" — a model the account actually has
        // (Seedream 4.0, Top-recommended in the console), so a valid key reads as connected.
        'probe_model' => env('BYTEPLUS_PROBE_MODEL', 'seedream-4-0-250828'),
    ],

    // xAI / Grok — a THIRD image provider (text-to-image, OpenAI-compatible). Key is
    // server-side only. base_url is the single global host (no per-model region). The
    // Test-connection button + per-model probe are no-spend GETs against /models (no
    // probe model id needed — the key alone is validated).
    'xai' => [
        'api_key' => env('XAI_API_KEY'),
        'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
        'timeout' => (int) env('XAI_TIMEOUT', 80),
    ],

    // AtlasCloud — an async VIDEO provider (generateVideo task API) for storyboard clips. Key is
    // server-side only. base_url is the single global API host (no per-model region). Video is
    // flat-rate (no inline USD) and storyboard clips never charge. timeout must fit a submit or a
    // poll+download under the media-queue worker timeout.
    'atlascloud' => [
        'api_key' => env('ATLASCLOUD_API_KEY'),
        'base_url' => env('ATLASCLOUD_BASE_URL', 'https://api.atlascloud.ai/api/v1'),
        'timeout' => (int) env('ATLASCLOUD_TIMEOUT', 80),
    ],

    // fal.ai — images AND video through one async queue (https://queue.fal.run/{model}). Key is
    // server-side only (`Authorization: Key ...`, fal's own scheme). catalog_url is the PUBLIC
    // no-auth model registry that powers the admin model pickers. fal returns no inline USD cost
    // (flat-rate; the admin per-image/per-clip price applies).
    'fal' => [
        'api_key' => env('FAL_API_KEY'),
        'base_url' => env('FAL_BASE_URL', 'https://queue.fal.run'),
        'catalog_url' => env('FAL_CATALOG_URL', 'https://fal.ai/api'),
        'timeout' => (int) env('FAL_TIMEOUT', 80),
    ],

    // Kling (Kuaishou) — images, VIRTUAL TRY-ON (kolors-virtual-try-on) and video through one async
    // task API. TWO credentials are accepted (https://kling.ai/dev/api-key) and both work:
    //   api_key                — the static key today's console issues ('api-key-kling-…'), sent as
    //                            the bearer verbatim. PREFERRED: set this one.
    //   access_key + secret_key — the legacy pair; a short-lived HS256 JWT is signed per request
    //                            with the secret (see KlingJwt). Used only when api_key is unset.
    // All are server-side only. base_url is the region host — api-singapore is the INTERNATIONAL
    // one (api-beijing serves the China account); a mismatched region rejects the credential.
    // Kling returns no inline USD cost (flat-rate) — the admin per-image/per-clip price applies.
    'kling' => [
        'api_key' => env('KLING_API_KEY'),
        'access_key' => env('KLING_ACCESS_KEY'),
        'secret_key' => env('KLING_SECRET_KEY'),
        'base_url' => env('KLING_BASE_URL', 'https://api-singapore.klingai.com'),
        'timeout' => (int) env('KLING_TIMEOUT', 80),
    ],

    // Shopify — the app-level OAuth credentials (client id/secret from the Partner
    // Dashboard). Platform-wide: per-store offline tokens live encrypted on each
    // ShopifyConnection, never here. Secret is server-side only (webhook HMAC + token
    // exchange); the DB-override lives in PlatformSettings (super-admin rotatable).
    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'timeout' => (int) env('SHOPIFY_TIMEOUT', 30),
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
