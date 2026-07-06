// === CONSTANTS ===
// The single source of truth for every magic string/number in the widget. No literal
// route, header, selector key, event name, storage key, timeout, or size budget lives
// mid-file anywhere else — modules import from here. English-only comments throughout.
//
// What the BROWSER is allowed to hold: only the PUBLIC site_key + the request Origin.
// The widget_secret + OpenRouter key are server-only and never appear here or in any
// payload the browser can read.

// The one namespaced global the widget owns on the host window (no other pollution).
export const NAMESPACE = '__TrayOn';

// The data-attribute on the merchant's <script> tag carrying the public site_key.
export const SITE_KEY_ATTR = 'data-site-key';

// The signed widget API (matches config/widget.php + routes/widget.php).
export const API_PREFIX = '/widget/v1';
export const ENDPOINTS = {
  bootstrap: '/bootstrap',
  generations: '/generations',
  generation: (id) => `/generations/${id}`,
  leads: '/leads',
  gallery: '/gallery',
  addToCart: '/events/add-to-cart',
  events: '/events',
};

// The auth header the ResolveWidgetSite middleware reads (config/widget.php). The GET
// bootstrap also accepts ?site_key=, but every fetch sends the header for consistency.
export const HEADER_SITE_KEY = 'X-Tray-Site-Key';
export const QUERY_SITE_KEY = 'site_key';

// Bootstrap query params (BootstrapController::QUERY_URL / QUERY_ANON_TOKEN).
export const QUERY_URL = 'url';
export const QUERY_ANON_TOKEN = 'anon_token';
export const QUERY_LIMIT = 'limit';

// How many past try-ons the gallery strip requests (server clamps to its own max).
export const GALLERY_LIMIT = 12;

// localStorage keys (namespaced so a host page can't collide).
export const STORAGE_ANON_TOKEN = 'trayon.anon_token';

// Cross-page / cross-tab "your try-on is ready" persistence. When a generation is created we
// persist ONLY handles (generationId, anonToken, productId, startedAt) under a SITE-SCOPED key
// so the shopper can keep browsing and still be notified on whatever page/tab they land on. A
// BroadcastChannel (also site-scoped) syncs completion/viewed/dismissed across open tabs; the
// `storage` event is the fallback when BroadcastChannel is unavailable. On completion we also
// store the EXPIRING signed result_url — never any photo or PII beyond what's already client-side.
export const STORAGE_PENDING_PREFIX = 'trayon.pending.'; // + site_key
export const BROADCAST_PREFIX = 'trayon.'; // + site_key (BroadcastChannel name)
export const PENDING_TTL_MS = 12 * 60 * 1000; // ~12 min: past the 90s poll + a browsing margin

// Pending lifecycle phases stored in the persisted entry.
export const PENDING_PHASE = { active: 'active', done: 'done', failed: 'failed', viewed: 'viewed' };

// Cross-tab message types on the BroadcastChannel (and the mirrored storage-event fallback).
export const PENDING_MSG = { done: 'done', failed: 'failed', viewed: 'viewed', dismissed: 'dismissed' };

// The sentinel marking an already-mounted button — guards idempotent injection.
export const MOUNT_SENTINEL_ATTR = 'data-trayon-mounted';

// Selector roles the widget reads from the per-site config (pdp-scanner source-of-truth).
export const SELECTOR_ROLES = {
  addToCart: 'add_to_cart',
  productImage: 'product_image',
  title: 'title',
  price: 'price',
  description: 'description',
  variations: 'variations',
};

// Appearance keys (App\Domain\Sites\WidgetAppearance). Read, never authored, here.
export const APPEARANCE = {
  placement: 'button_placement',
  label: 'button_label',
  buttonBg: 'button_bg',
  buttonText: 'button_text_color',
  popupTheme: 'popup_theme',
  popupAccent: 'popup_accent',
  askHeight: 'ask_height',
  customAnchor: 'custom_anchor_selector',
  customPosition: 'custom_position',
};

// Placement values (WidgetAppearance::PLACEMENT_*).
export const PLACEMENT = {
  afterAtc: 'after_add_to_cart',
  beforeAtc: 'before_add_to_cart',
  fixedBR: 'fixed_bottom_right',
  fixedBL: 'fixed_bottom_left',
  custom: 'custom',
};

// Where the button sits relative to a custom anchor (WidgetAppearance::POSITION_*).
export const POSITION = {
  before: 'before',
  after: 'after',
  prepend: 'prepend',
  append: 'append',
};

export const POPUP_THEME = { light: 'light', dark: 'dark' };

// Generation status machine (App\Models\Generation::STATUS_*). The widget polls; the
// server is the truth — never infer success locally.
export const GEN_STATUS = {
  pending: 'pending',
  processing: 'processing',
  succeeded: 'succeeded',
  failed: 'failed',
  cancelled: 'cancelled',
};

// Typed gate-denial reasons (App\Http\Widget\WidgetGateDecision::REASON_*).
export const GATE_REASON = {
  signupRequired: 'signup_required',
  postSignupLimit: 'post_signup_limit_reached',
  insufficientCredits: 'insufficient_credits',
  accountInactive: 'account_inactive',
  rateLimited: 'rate_limited',
};

// Request field names (App\Http\Requests\Widget\StartGenerationRequest::FIELD_*).
export const GEN_FIELD = {
  photo: 'photo',
  height: 'height',
  productId: 'product_id',
  variantId: 'variant_id',
  clientRequestId: 'client_request_id',
  consent: 'consent',
  anonToken: 'anon_token',
  extra: 'extra',
};

// Lead-capture field names (App\Http\Requests\Widget\CaptureLeadRequest::FIELD_*).
export const LEAD_FIELD = {
  fullName: 'full_name',
  email: 'email',
  phone: 'phone',
  marketingConsent: 'marketing_consent',
  anonToken: 'anon_token',
  source: 'source',
};

// Add-to-cart event field names (App\Http\Requests\Widget\AddToCartEventRequest::FIELD_*).
export const CART_EVENT_FIELD = {
  anonToken: 'anon_token',
  generationId: 'generation_id',
  variantId: 'variant_id',
};

// Height sanity range (mirrors StartGenerationRequest::HEIGHT_MIN/MAX_CM).
export const HEIGHT_MIN_CM = 50;
export const HEIGHT_MAX_CM = 260;

// Client-side image pipeline. Downscale BEFORE upload (coordinated with ai-openrouter:
// MAX_EDGE_PX is the agreed long-edge target; HARD_MAX_BYTES is an absolute guard before
// any decode). The server re-validates; this keeps uploads sane + the main thread free.
export const IMAGE_ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
export const IMAGE_HARD_MAX_BYTES = 12 * 1024 * 1024; // 12 MB absolute pre-decode guard
export const IMAGE_MAX_EDGE_PX = 1280; // agreed long-edge downscale target
export const IMAGE_OUTPUT_TYPE = 'image/jpeg';
export const IMAGE_OUTPUT_QUALITY = 0.85;

// Timers (ms). Boot is idle-scheduled; the observer is debounced; polling is bounded.
export const BOOT_IDLE_TIMEOUT_MS = 3000;
export const OBSERVER_DEBOUNCE_MS = 150;
export const POLL_INTERVAL_MS = 1500;
export const POLL_TIMEOUT_MS = 90000;
export const MAX_POLLS = Math.ceil(POLL_TIMEOUT_MS / POLL_INTERVAL_MS);

// Internal widget events (dispatched on the shadow host element, not the host window).
export const EVENTS = {
  variantChanged: 'trayon:variant-changed',
  open: 'trayon:open',
};

// Modal step ids (the flow product-ux-architect specced: photo -> details -> consent).
export const STEP = { photo: 'photo', details: 'details', consent: 'consent' };

// --- Activity tracking (track.js) --------------------------------------------
// Behavioral signal collection: ONE page_view per page load + MEANINGFUL interactions only
// (product view, variant change, Tray-On open, add-to-cart) — never arbitrary DOM clicks.
// Events are batched into a small in-memory queue and flushed fire-and-forget on idle /
// pagehide (sendBeacon where available, else fetch keepalive) so nothing blocks interaction
// and nothing is lost on navigation. The server response is ignored.

// The two event kinds the ingest contract accepts (POST /events { events: [{ kind, ... }] }).
export const TRACK_KIND = { pageView: 'page_view', interaction: 'interaction' };

// The MEANINGFUL interaction types (interaction.type in the payload). Curated — not clicks.
export const TRACK_INTERACTION = {
  productView: 'product_view',
  variantChange: 'variant_change',
  tryonOpen: 'tryon_open',
  addToCart: 'add_to_cart',
};

// Batching: cap the queue so a long session can't grow unbounded, and auto-flush once it
// fills. Idle flush rides the same requestIdleCallback path the loader boots on.
export const TRACK_MAX_QUEUE = 20;
export const TRACK_FLUSH_IDLE_TIMEOUT_MS = 2000;

// The bootstrap flag that can DISABLE tracking per-site (privacy/consent). Tracking defaults
// to ON unless the bootstrap explicitly returns tracking_enabled === false (coordinated with
// laravel-backend: absent flag => on). A variant-label is trimmed to this max before sending.
export const TRACK_LABEL_MAX = 120;
