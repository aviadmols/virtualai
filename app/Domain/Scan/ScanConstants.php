<?php

namespace App\Domain\Scan;

/**
 * ScanConstants — the single home for the scan layer's shared literals.
 *
 * CONST-at-top, project-wide: selector role keys, confidence thresholds, the
 * source-trust ranking, escalation-heuristic limits and the failure-reason codes
 * all live here so no magic string is scattered through the fetch/represent/map
 * classes. A service references ScanConstants::X, never a literal.
 */
final class ScanConstants
{
    // === SELECTOR ROLES ===
    // The six page selectors the widget needs at runtime.
    public const ROLE_ADD_TO_CART = 'add_to_cart';

    public const ROLE_PRODUCT_IMAGE = 'product_image';

    public const ROLE_TITLE = 'title';

    public const ROLE_PRICE = 'price';

    public const ROLE_DESCRIPTION = 'description';

    public const ROLE_VARIATIONS = 'variations';

    public const SELECTOR_ROLES = [
        self::ROLE_ADD_TO_CART,
        self::ROLE_PRODUCT_IMAGE,
        self::ROLE_TITLE,
        self::ROLE_PRICE,
        self::ROLE_DESCRIPTION,
        self::ROLE_VARIATIONS,
    ];

    // === DIMENSION ROLES (physical size + weight) ===
    // The merchant can visually mark WHERE size/weight are read on the page, just
    // like the six selector roles above. But these are a SEPARATE set on purpose,
    // NOT appended to SELECTOR_ROLES, because SELECTOR_ROLES is the widget-RUNTIME
    // selector contract: it is shipped to the storefront in detected_selectors, it
    // is iterated by ScanReview to build the ConfirmGate's blocking selector rows,
    // and it drives per-selector confidence. Size/weight are never queried by the
    // widget at runtime — their selector is used ONCE at confirm time to read a
    // value INTO Product.physical_dimensions (a fit hint for the try-on prompt).
    // Keeping them apart means: (a) detected_selectors stays exactly the six runtime
    // roles the widget expects; (b) an empty size/weight pick never turns into a
    // not_detected blocking row that would break every existing product's confirm;
    // (c) the persisted picked selector + its read value live under
    // physical_dimensions, next to the AI-extracted dimensions, not in the runtime bag.
    public const ROLE_SIZE = 'size';

    public const ROLE_WEIGHT = 'weight';

    public const DIMENSION_ROLES = [
        self::ROLE_SIZE,
        self::ROLE_WEIGHT,
    ];

    // How a picked dimension is stored inside Product.physical_dimensions: the
    // dimension roles nest under this key as { size: {selector, value}, ... } so
    // the merchant-marked source is auditable and re-readable, and never collides
    // with the AI-extracted dimension keys (chest/length/material/…) at the top level.
    public const DIMENSION_PICKS_KEY = 'picks';

    public const DIMENSION_PICK_SELECTOR = 'selector';

    public const DIMENSION_PICK_VALUE = 'value';

    // === FIELD SOURCES (trust ranking, high -> low) ===
    // Where an extracted field came from. model_inferred is the lowest-trust and
    // is ALWAYS flagged for merchant review.
    public const SOURCE_JSONLD = 'jsonld';

    public const SOURCE_OG = 'og';

    public const SOURCE_MICRODATA = 'microdata';

    public const SOURCE_DOM = 'dom';

    public const SOURCE_SCREENSHOT = 'screenshot';

    public const SOURCE_MODEL_INFERRED = 'model_inferred';

    // The merchant's own store record (Shopify Admin API). Nothing was guessed, so it
    // is the highest-trust source there is — a field from it is never "low confidence".
    public const SOURCE_SHOPIFY = 'shopify';

    // Confidence weight per source — a high-trust source lifts a field's score.
    public const SOURCE_WEIGHT = [
        self::SOURCE_SHOPIFY => 1.0,
        self::SOURCE_JSONLD => 1.0,
        self::SOURCE_OG => 0.9,
        self::SOURCE_MICRODATA => 0.9,
        self::SOURCE_DOM => 0.75,
        self::SOURCE_SCREENSHOT => 0.6,
        self::SOURCE_MODEL_INFERRED => 0.4,
    ];

    // === CONFIDENCE THRESHOLDS ===
    // The aggregate floor below which laravel-backend fails the scan. The review
    // queue flags any field/selector under REVIEW_FLOOR for merchant attention.
    public const CONFIDENCE_THRESHOLD = 0.45;

    public const REVIEW_FLOOR = 0.7;

    // === CONFIDENCE LEVELS (the A4 review-form contract) ===
    // The four buckets the review UI + the badge map (design-tokens §5) key off:
    // a numeric score becomes exactly one of these. The bucketing thresholds are a
    // pdp-scanner decision (the token map keys the bucketed level, not the raw score).
    // high (calm, ready) ≥ REVIEW_FLOOR; medium (please confirm) ≥ LEVEL_MEDIUM_FLOOR;
    // low (must review) > 0; not_detected = nothing extracted (null value / 0 score).
    public const LEVEL_HIGH = 'high';

    public const LEVEL_MEDIUM = 'medium';

    public const LEVEL_LOW = 'low';

    public const LEVEL_NOT_DETECTED = 'not_detected';

    public const CONFIDENCE_LEVELS = [
        self::LEVEL_HIGH,
        self::LEVEL_MEDIUM,
        self::LEVEL_LOW,
        self::LEVEL_NOT_DETECTED,
    ];

    // medium floor: at/above REVIEW_FLOOR is high; this floor splits medium vs low.
    public const LEVEL_MEDIUM_FLOOR = 0.45;

    // The badge i18n key per level (design-tokens §5: not_detected -> ".none").
    public const LEVEL_I18N_KEY = [
        self::LEVEL_HIGH => 'scan.confidence.high',
        self::LEVEL_MEDIUM => 'scan.confidence.medium',
        self::LEVEL_LOW => 'scan.confidence.low',
        self::LEVEL_NOT_DETECTED => 'scan.confidence.none',
    ];

    // A level BLOCKS confirm until the merchant reviews/edits the row. high/medium
    // are pre-confirmable; low + not_detected must be touched (the no-auto-approve gate).
    public const LEVELS_BLOCKING_CONFIRM = [
        self::LEVEL_LOW,
        self::LEVEL_NOT_DETECTED,
    ];

    // A selector that resolves to exactly one element gets full verification
    // weight; a 0/>1 match is penalised hard and flagged.
    public const SELECTOR_MATCH_ONE_WEIGHT = 1.0;

    public const SELECTOR_MATCH_MULTI_WEIGHT = 0.35;

    public const SELECTOR_MATCH_ZERO_WEIGHT = 0.0;

    // === SELECTOR STRATEGY WEIGHTS (stable -> brittle) ===
    public const STRATEGY_ID = 'id';

    public const STRATEGY_DATA_ATTR = 'data_attr';

    public const STRATEGY_ARIA = 'aria';

    public const STRATEGY_SEMANTIC = 'semantic';

    public const STRATEGY_ITEMPROP = 'itemprop';

    public const STRATEGY_CLASS = 'class';

    public const STRATEGY_POSITIONAL = 'positional';

    public const STRATEGY_WEIGHT = [
        self::STRATEGY_ID => 1.0,
        self::STRATEGY_DATA_ATTR => 0.95,
        self::STRATEGY_ARIA => 0.9,
        self::STRATEGY_ITEMPROP => 0.9,
        self::STRATEGY_SEMANTIC => 0.85,
        self::STRATEGY_CLASS => 0.6,
        self::STRATEGY_POSITIONAL => 0.3, // brittle — always flagged for review
    ];

    // === FETCH ===
    public const FETCH_VIA_HTTP = 'http';

    public const FETCH_VIA_HEADLESS = 'headless';

    // === EGRESS LIMITS (SSRF / DoS bounds) ===
    // Defaults when the SCRAPER_* config is absent. The byte cap is enforced
    // MID-STREAM (BoundedSink); the redirect cap bounds the manual re-guarded hops.
    public const EGRESS_MAX_BYTES = 3_145_728;     // 3 MiB

    public const EGRESS_MAX_REDIRECTS = 5;

    public const EGRESS_HTTP_TIMEOUT = 15;         // seconds

    public const EGRESS_RENDER_TIMEOUT = 30;       // seconds

    public const EGRESS_ROBOTS_TIMEOUT = 5;        // seconds

    public const EGRESS_ROBOTS_MAX_BYTES = 524_288; // 512 KiB — robots.txt is tiny

    // === FAILURE REASONS (merchant-facing class) ===
    public const FAIL_INVALID_URL = 'invalid_url';

    public const FAIL_ROBOTS_BLOCKED = 'robots_blocked';

    public const FAIL_BOT_BLOCKED = 'bot_blocked';

    public const FAIL_RENDER_EMPTY = 'render_empty';

    public const FAIL_TIMEOUT = 'timeout';

    public const FAIL_TOO_LARGE = 'too_large';

    public const FAIL_HTTP_ERROR = 'http_error';

    public const FAIL_RENDER_DISABLED = 'render_disabled';

    public const FAIL_INVALID_EXTRACTION = 'invalid_extraction';

    public const FAIL_BELOW_THRESHOLD = 'below_threshold';

    // === HEADLESS-ESCALATION HEURISTIC ===
    // A raw HTTP body looks "rendered enough" to skip headless when it has a
    // product signal (title/price/og:image/JSON-LD Product) AND enough visible
    // text. SPA shells (tiny text, framework root marker, no product node) escalate.
    public const MIN_TEXT_DENSITY_CHARS = 600;   // min visible text to trust HTTP

    public const SPA_ROOT_MARKERS = [
        'id="root"',
        'id="app"',
        'id="__next"',
        'id="__nuxt"',
        'ng-app',
        'data-reactroot',
    ];

    // === REPRESENTATION BUDGET ===
    // Cap the cleaned HTML handed to the model (chars). Drop chrome/boilerplate
    // before product content if the page exceeds this.
    public const REPRESENTATION_MAX_CHARS = 24_000;

    // Tags whose subtree is pure noise for extraction — stripped wholesale.
    public const NOISE_TAGS = ['script', 'style', 'svg', 'noscript', 'iframe', 'template'];

    // application/ld+json scripts are GOLD — kept and lifted, never stripped.
    public const JSONLD_TYPE = 'application/ld+json';

    // === PRICE PLACEHOLDER REJECTS (lazy image guard) ===
    public const PLACEHOLDER_IMAGE_MARKERS = ['data:image', '1x1', 'spacer', 'blank.gif', 'transparent'];
}
