<?php

use App\Domain\Scan\ScanConstants;
use App\Domain\Shopify\Webhooks\AcknowledgeGdprWebhookJob;
use App\Domain\Shopify\Webhooks\HandleAppUninstalledJob;
use App\Domain\Shopify\Webhooks\HandleProductDeleteJob;
use App\Domain\Shopify\Webhooks\HandleProductUpdateJob;

// === CONSTANTS ===
// Shopify integration contract knobs. Pinned + guarded so config:cache stays idempotent.
// The Admin API version is PINNED — quarterly bumps are a config change reviewed against
// the changelog, never an implicit float to "latest".
defined('SHOPIFY_API_VERSION') || define('SHOPIFY_API_VERSION', '2026-04');

// OAuth scopes — minimal by design (docs/shopify/DECISIONS.md §3): products for
// sync + media push, themes (read) for the onboarding checklist's "try-on button
// enabled" check. read_orders (purchase attribution) is DEFERRED to Phase 6: it is
// "protected customer data", and requesting it WITHOUT a Protected-Customer-Data
// declaration makes Shopify 403 the ENTIRE Admin API (TS-SHOPIFY: 403 on products +
// themes). Re-add read_orders in Phase 6 together with the protected-data declaration.
defined('SHOPIFY_SCOPES') || define('SHOPIFY_SCOPES', 'read_products,write_products,read_themes');

// The Theme App Extension identity (shopify/extensions/trayon-widget). The uid keys the
// theme-file checks (a block's type carries it: shopify://apps/vsio/blocks/{block}/{uid})
// and, with the embed block's handle, the theme-editor deep link that activates the app
// embed. This MUST equal the uuid Shopify registered for the extension — read it off any
// live theme block's `type` string (never hand-fabricate it: a wrong uid makes the deep
// link 404 "App embed does not exist" and the theme inspector never match).
defined('SHOPIFY_THEME_EXT_UID') || define('SHOPIFY_THEME_EXT_UID', '019f79f2-87b2-7b18-8bdc-ab4561865165');
defined('SHOPIFY_THEME_EMBED_HANDLE') || define('SHOPIFY_THEME_EMBED_HANDLE', 'trayon');

// Webhook receipt housekeeping: how long a processed receipt keeps its payload, and
// when a stuck (received/queued) receipt is considered lost and re-dispatched.
defined('SHOPIFY_RECEIPT_RETENTION_DAYS') || define('SHOPIFY_RECEIPT_RETENTION_DAYS', 14);
defined('SHOPIFY_RECEIPT_STUCK_MINUTES') || define('SHOPIFY_RECEIPT_STUCK_MINUTES', 5);
defined('SHOPIFY_RECEIPT_MAX_ATTEMPTS') || define('SHOPIFY_RECEIPT_MAX_ATTEMPTS', 5);

// Where the OAuth flows bounce the merchant: register-or-login, and the panel an
// install lands on. Paths (not route names) so the panel owner can re-point them
// without touching the OAuth controllers.
defined('SHOPIFY_MERCHANT_LOGIN_PATH') || define('SHOPIFY_MERCHANT_LOGIN_PATH', '/merchant/login');
defined('SHOPIFY_MERCHANT_PANEL_PATH') || define('SHOPIFY_MERCHANT_PANEL_PATH', '/merchant');

// --- Phase 3: product sync ---
// Products per catalog page. Modest on purpose: one page's GraphQL COST must stay well
// inside Shopify's leaky bucket even for 100-variant products.
defined('SHOPIFY_SYNC_PAGE_SIZE') || define('SHOPIFY_SYNC_PAGE_SIZE', 25);
defined('SHOPIFY_SYNC_MAX_PAGES') || define('SHOPIFY_SYNC_MAX_PAGES', 400);
defined('SHOPIFY_SYNC_SEARCH_LIMIT') || define('SHOPIFY_SYNC_SEARCH_LIMIT', 20);

// How many times ONE page may be parked (throttled) before the run is called stalled.
// A park is not a failure and never spends a queue try (SyncShopifyCatalogJob::park), so
// this — not $tries — is what bounds a store that rate-limits us forever.
defined('SHOPIFY_SYNC_MAX_PARKS') || define('SHOPIFY_SYNC_MAX_PARKS', 40);

// The catalog filter — only products the storefront can actually sell get imported.
defined('SHOPIFY_SYNC_CATALOG_QUERY') || define('SHOPIFY_SYNC_CATALOG_QUERY', 'status:active');

// "Import all" ceiling. One click must never queue a 40k-product store into `bulk`.
// A super-admin raises it per platform (PlatformSettings::SHOPIFY_IMPORT_CAP).
defined('SHOPIFY_IMPORT_SOFT_CAP') || define('SHOPIFY_IMPORT_SOFT_CAP', 1000);
defined('SHOPIFY_IMPORT_SELECTION_MAX') || define('SHOPIFY_IMPORT_SELECTION_MAX', 250);

// --- Phase 5: push approved images to product media ---
// Shopify processes an uploaded image ASYNCHRONOUSLY (UPLOADED -> PROCESSING -> READY|FAILED).
// The pusher POLLS until READY, and NEVER deletes a replaced image before the replacement is
// confirmed READY — so the storefront is valid at every intermediate step.
defined('SHOPIFY_MEDIA_READY_ATTEMPTS') || define('SHOPIFY_MEDIA_READY_ATTEMPTS', 20);
defined('SHOPIFY_MEDIA_READY_DELAY_SECONDS') || define('SHOPIFY_MEDIA_READY_DELAY_SECONDS', 3);

// How many media ONE PAGE of a product's gallery read returns (the placement chooser + the
// snapshot). The read is PAGINATED and walks to the end: a product may carry up to 250 media,
// and a TRUNCATED gallery snapshotted as complete would license a destructive push whose undo
// cannot restore what it never saw. per_product x max_pages must cover the 250 ceiling; a
// gallery that still cannot be read to its end FAILS CLOSED (the push is refused).
defined('SHOPIFY_MEDIA_PER_PRODUCT') || define('SHOPIFY_MEDIA_PER_PRODUCT', 50);
defined('SHOPIFY_MEDIA_MAX_PAGES') || define('SHOPIFY_MEDIA_MAX_PAGES', 10);

// Byte ceiling for ONE downloaded original when snapshotting a gallery. A hostile/absurd
// object can never OOM the worker; exceeding it FAILS the snapshot (and so refuses the push).
defined('SHOPIFY_MEDIA_SNAPSHOT_MAX_BYTES') || define('SHOPIFY_MEDIA_SNAPSHOT_MAX_BYTES', 20971520); // 20 MiB

// How many times ONE push may be parked (throttled) before the throttle is a real failure.
defined('SHOPIFY_MEDIA_MAX_PARKS') || define('SHOPIFY_MEDIA_MAX_PARKS', 20);

// When a `pushing` asset is considered LOST. A SIGKILL/OOM worker never calls failed(), so the
// asset would stay `pushing` forever and the merchant could never push that image again (push
// and re-push both deny an in-flight asset). Past this age the push is reclaimable — and the
// reclaim can never duplicate the media, because the pusher RESUMES the persisted
// shopify_media_id. Must exceed the longest legitimate in-flight window: max_parks x 30s of
// throttle parks (10 min) plus the job timeout.
defined('SHOPIFY_MEDIA_STUCK_MINUTES') || define('SHOPIFY_MEDIA_STUCK_MINUTES', 30);

// The alt text an image carries into the store. Substituted with strtr() — NEVER
// Blade::render() — because this template is admin/merchant-editable text (RCE prevention).
defined('SHOPIFY_MEDIA_ALT_TEMPLATE') || define('SHOPIFY_MEDIA_ALT_TEMPLATE', '{product_name} — {operation}');

// Throttle policy (Shopify rate-limits by query COST). Retry-After is honoured when
// present; these bound the retries and the wait so a worker is never parked for hours.
defined('SHOPIFY_THROTTLE_MAX_RETRIES') || define('SHOPIFY_THROTTLE_MAX_RETRIES', 3);
defined('SHOPIFY_THROTTLE_BACKOFF_SECONDS') || define('SHOPIFY_THROTTLE_BACKOFF_SECONDS', 2);
defined('SHOPIFY_THROTTLE_MAX_WAIT_SECONDS') || define('SHOPIFY_THROTTLE_MAX_WAIT_SECONDS', 30);

return [

    // The pinned Admin API version every GraphQL call uses.
    'api_version' => env('SHOPIFY_API_VERSION', SHOPIFY_API_VERSION),

    // Comma-separated OAuth scopes requested at install.
    'scopes' => env('SHOPIFY_SCOPES', SHOPIFY_SCOPES),

    // The webhook topics the app subscribes to after install. GDPR topics are
    // MANDATORY compliance webhooks (registered via the Partner Dashboard app config,
    // answered by the same intake endpoint).
    'topics' => [
        'products/update',
        'products/delete',
        // orders/paid is DEFERRED to Phase 6 with read_orders (protected customer data) —
        // registering it needs the read_orders scope, which we no longer request.
        'app/uninstalled',
    ],

    // The three MANDATORY compliance webhooks. Registered from the Partner Dashboard app
    // config (not the API), answered by the SAME intake endpoint from day one
    // (docs/shopify/DECISIONS.md §4). Phase 7 re-points them at the real erasure.
    'gdpr_topics' => [
        'customers/data_request',
        'customers/redact',
        'shop/redact',
    ],

    // topic → the tenant-bound handler job dispatched by ShopifyWebhookDispatcher.
    // The handler contract is (int $accountId, int $receiptId) — see
    // HandleShopifyWebhookJob. An unmapped topic fails the receipt loudly (it is a
    // durable, replayable row) instead of vanishing.
    // Phase 3 adds products/update, products/delete; Phase 6 adds orders/paid.
    'topic_handlers' => [
        'app/uninstalled' => HandleAppUninstalledJob::class,
        // Phase 3 — product sync. The push is a SIGNAL: the handler re-reads the product
        // through the Admin API (the source of truth) rather than trusting the payload.
        'products/update' => HandleProductUpdateJob::class,
        'products/delete' => HandleProductDeleteJob::class,
        // GDPR: durably receipted + acknowledged now; real erasure lands in Phase 7.
        'customers/data_request' => AcknowledgeGdprWebhookJob::class,
        'customers/redact' => AcknowledgeGdprWebhookJob::class,
        'shop/redact' => AcknowledgeGdprWebhookJob::class,
    ],

    // Where the install flows send the merchant (see the guarded defines above).
    'merchant_login_path' => env('SHOPIFY_MERCHANT_LOGIN_PATH', SHOPIFY_MERCHANT_LOGIN_PATH),
    'merchant_panel_path' => env('SHOPIFY_MERCHANT_PANEL_PATH', SHOPIFY_MERCHANT_PANEL_PATH),

    // The Theme App Extension identity (see the guarded defines above).
    'theme_extension' => [
        'uid' => SHOPIFY_THEME_EXT_UID,
        'embed_handle' => SHOPIFY_THEME_EMBED_HANDLE,
    ],

    // Webhook-receipt housekeeping knobs (see the recovery job).
    'receipts' => [
        'retention_days' => (int) env('SHOPIFY_RECEIPT_RETENTION_DAYS', SHOPIFY_RECEIPT_RETENTION_DAYS),
        'stuck_after_minutes' => (int) env('SHOPIFY_RECEIPT_STUCK_MINUTES', SHOPIFY_RECEIPT_STUCK_MINUTES),
        'max_attempts' => (int) env('SHOPIFY_RECEIPT_MAX_ATTEMPTS', SHOPIFY_RECEIPT_MAX_ATTEMPTS),
    ],

    // Catalog-walk knobs (SyncShopifyCatalogJob + ShopifyProductSource).
    'sync' => [
        'page_size' => (int) env('SHOPIFY_SYNC_PAGE_SIZE', SHOPIFY_SYNC_PAGE_SIZE),
        'max_pages' => (int) env('SHOPIFY_SYNC_MAX_PAGES', SHOPIFY_SYNC_MAX_PAGES),
        'max_parks' => (int) env('SHOPIFY_SYNC_MAX_PARKS', SHOPIFY_SYNC_MAX_PARKS),
        'search_limit' => (int) env('SHOPIFY_SYNC_SEARCH_LIMIT', SHOPIFY_SYNC_SEARCH_LIMIT),
        'catalog_query' => env('SHOPIFY_SYNC_CATALOG_QUERY', SHOPIFY_SYNC_CATALOG_QUERY),
    ],

    // "Import all" ceilings (StartShopifySync; the platform admin may raise the cap).
    'import' => [
        'soft_cap' => (int) env('SHOPIFY_IMPORT_SOFT_CAP', SHOPIFY_IMPORT_SOFT_CAP),
        'selection_max' => (int) env('SHOPIFY_IMPORT_SELECTION_MAX', SHOPIFY_IMPORT_SELECTION_MAX),
    ],

    // Media push (Phase 5): staged upload -> productCreateMedia -> poll READY -> placement.
    'media' => [
        'ready_attempts' => (int) env('SHOPIFY_MEDIA_READY_ATTEMPTS', SHOPIFY_MEDIA_READY_ATTEMPTS),
        'ready_delay_seconds' => (int) env('SHOPIFY_MEDIA_READY_DELAY_SECONDS', SHOPIFY_MEDIA_READY_DELAY_SECONDS),
        'per_product' => (int) env('SHOPIFY_MEDIA_PER_PRODUCT', SHOPIFY_MEDIA_PER_PRODUCT),
        'max_pages' => (int) env('SHOPIFY_MEDIA_MAX_PAGES', SHOPIFY_MEDIA_MAX_PAGES),
        'snapshot_max_bytes' => (int) env('SHOPIFY_MEDIA_SNAPSHOT_MAX_BYTES', SHOPIFY_MEDIA_SNAPSHOT_MAX_BYTES),
        'max_parks' => (int) env('SHOPIFY_MEDIA_MAX_PARKS', SHOPIFY_MEDIA_MAX_PARKS),
        'stuck_after_minutes' => (int) env('SHOPIFY_MEDIA_STUCK_MINUTES', SHOPIFY_MEDIA_STUCK_MINUTES),
        'alt_template' => env('SHOPIFY_MEDIA_ALT_TEMPLATE', SHOPIFY_MEDIA_ALT_TEMPLATE),
    ],

    // Admin API throttling (ShopifyGraphQLClient honours Retry-After within this budget).
    'throttle' => [
        'max_retries' => (int) env('SHOPIFY_THROTTLE_MAX_RETRIES', SHOPIFY_THROTTLE_MAX_RETRIES),
        'backoff_seconds' => (int) env('SHOPIFY_THROTTLE_BACKOFF_SECONDS', SHOPIFY_THROTTLE_BACKOFF_SECONDS),
        'max_wait_seconds' => (int) env('SHOPIFY_THROTTLE_MAX_WAIT_SECONDS', SHOPIFY_THROTTLE_MAX_WAIT_SECONDS),
    ],

    // The platform-default Online Store 2.0 selectors an imported product ships with.
    // A Shopify storefront has a known DOM contract (and the Theme App Extension supplies
    // the authoritative product/variant context), so nothing is DETECTED for a Shopify
    // product — these defaults are it. A merchant may still correct any of them.
    'selectors' => [
        ScanConstants::ROLE_ADD_TO_CART => 'form[action*="/cart/add"] button[type="submit"], button[name="add"]',
        ScanConstants::ROLE_PRODUCT_IMAGE => '.product__media img, .product-single__photo img, [class*="product-gallery"] img',
        ScanConstants::ROLE_TITLE => '.product__title, .product-single__title, h1',
        ScanConstants::ROLE_PRICE => '.price__regular .price-item, .price-item--regular, .product__price',
        ScanConstants::ROLE_DESCRIPTION => '.product__description, .product-single__description, [class*="product-description"]',
        ScanConstants::ROLE_VARIATIONS => 'variant-selects, variant-radios, .product-form__input',
    ],
];
