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
};

// Placement values (WidgetAppearance::PLACEMENT_*).
export const PLACEMENT = {
  afterAtc: 'after_add_to_cart',
  beforeAtc: 'before_add_to_cart',
  fixedBR: 'fixed_bottom_right',
  fixedBL: 'fixed_bottom_left',
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
