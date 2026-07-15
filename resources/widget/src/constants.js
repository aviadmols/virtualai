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

// --- The <script> tag contract -------------------------------------------------------
// data-site-key is the only required attribute. The Shopify Theme App Extension ALSO
// stamps the platform + the live product/variant context (shopify/extensions/trayon-widget)
// so the widget never has to guess the product from the URL and can drive the real Ajax cart.
export const SITE_KEY_ATTR = 'data-site-key';
export const PLATFORM_ATTR = 'data-platform';
export const PRODUCT_ID_ATTR = 'data-product-id';
export const PRODUCT_HANDLE_ATTR = 'data-product-handle';
export const VARIANT_ID_ATTR = 'data-variant-id';

// Host platforms the tag can declare. Anything else (or absent) = the generic DOM-click path.
export const PLATFORM = { shopify: 'shopify' };

// The signed widget API (matches config/widget.php + routes/widget.php).
export const API_PREFIX = '/widget/v1';
export const ENDPOINTS = {
  bootstrap: '/bootstrap',
  generations: '/generations',
  generation: (id) => `/generations/${id}`,
  // Same-origin result BYTES (streams the image) — the only way navigator.share({files})
  // can build a File from a cross-origin storefront. Owned by laravel-backend.
  generationImage: (id) => `/generations/${id}/image`,
  leads: '/leads',
  gallery: '/gallery',
  addToCart: '/events/add-to-cart',
  events: '/events',
  bannerEvent: '/banners/event',
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

// --- The split bundle ----------------------------------------------------------------
// The CORE (this bundle) is what the merchant's LCP/CLS/SEO pay for on every page view:
// loader + PDP detect + trigger + the floating HUD + the modal SKELETON + resume/poll.
// Everything the shopper only needs AFTER an interaction ships in a lazily fetched chunk:
//   modal  — the modal body, upload/result/strip/actions, cart, share, the Outfit webfont;
//   club   — the customer club, merchant banners, member pricing (fetched on IDLE).
// Each chunk is its own self-contained IIFE (a classic <script src>, never an ESM chunk —
// see TS-BUILD-005) that registers itself on window[NAMESPACE].__ready(name, exports).
export const CHUNK = { modal: 'modal', club: 'club' };
export const CHUNK_FILES = { modal: 'widget.modal.js', club: 'widget.club.js' };

// The registration hook the chunks call, and the kernel the chunks read the core singletons
// from (one namespaced object — no other window pollution).
export const CHUNK_READY_FN = '__ready';
export const KERNEL_KEY = '__k';

// What the shopper sees while the modal chunk is in flight (§6.3 of the redesign spec):
// under 250 ms the trigger's own spinner IS the feedback (zero CLS, nothing else appears);
// past it we open the skeleton shell; past 8 s (or on a fetch error) we surface a retryable HUD.
export const CHUNK_SHELL_DELAY_MS = 250;
export const CHUNK_TIMEOUT_MS = 8000;
// Modal close fade — matches the reference overlay transition (0.25s).
export const OVERLAY_CLOSE_MS = 250;

// Cross-page / cross-tab "your look is ready" persistence. When a generation is created we
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
  askConsent: 'ask_consent',
  customAnchor: 'custom_anchor_selector',
  customPosition: 'custom_position',
};

// Placement values (WidgetAppearance::PLACEMENT_*). `on_product_image` is the redesign's one
// new enum value: an OPTIONAL glass trigger laid over the product photo. It reuses the existing
// `product_image` selector role — no new selector, no new appearance key.
export const PLACEMENT = {
  afterAtc: 'after_add_to_cart',
  beforeAtc: 'before_add_to_cart',
  fixedBR: 'fixed_bottom_right',
  fixedBL: 'fixed_bottom_left',
  custom: 'custom',
  onImage: 'on_product_image',
};

// Where the button sits relative to a custom anchor (WidgetAppearance::POSITION_*).
export const POSITION = {
  before: 'before',
  after: 'after',
  prepend: 'prepend',
  append: 'append',
};

export const POPUP_THEME = { light: 'light', dark: 'dark' };

// The on-image trigger positions its host-DOM wrapper absolutely INSIDE the merchant's product-image
// container. These are the only properties the widget ever writes on a node it does not own, and they
// are declared here (never a literal mid-file). Absolute positioning inside an existing box shifts
// nothing — the CLS gate proves it.
export const ON_IMAGE_WRAPPER_STYLE = {
  position: 'absolute',
  'inset-block-end': '16px',
  'inset-inline-start': '16px',
  'z-index': '2',
  'max-width': 'calc(100% - 32px)',
};

// The single host-style write the on-image placement may make: make the image container a
// positioning context, and ONLY when its computed position is `static`. Reverted on teardown.
export const HOST_POSITION_STATIC = 'static';
export const HOST_POSITION_RELATIVE = 'relative';

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

// --- The REAL add-to-cart -------------------------------------------------------------
// A strategy layer, not a blind click. On Shopify (the tag declares data-platform="shopify")
// we POST the store's own Ajax cart with the REAL numeric variant id and then VERIFY the line
// landed by re-reading /cart.js — a 200 from add.js is not proof. Everywhere else we fall back
// to driving the theme's own add-to-cart element. We never reimplement checkout or pricing.
export const CART_STRATEGY = { shopifyAjax: 'shopify_ajax', domClick: 'dom_click' };

// Shopify's public Ajax Cart API, on the MERCHANT's origin (never ours).
export const SHOPIFY_CART_ADD = '/cart/add.js';
export const SHOPIFY_CART_GET = '/cart.js';

// The line-item property that attributes a purchase back to the try-on that produced it.
// Shopify hides `_`-prefixed properties from the customer; Phase 6 reads this off the order.
export const CART_LINE_PROPERTY = '_trayon';

// Typed add-to-cart outcomes the result screen renders (never a raw status code).
export const CART_OUTCOME = {
  added: 'added',
  addedOptimistic: 'added_optimistic', // add.js said 200 but the /cart.js verify was inconclusive
  unavailable: 'unavailable', // 422: sold out / not purchasable
  failed: 'failed',
};

// Shopify returns 422 for a sold-out / unpurchasable variant.
export const CART_STATUS_UNPROCESSABLE = 422;

// How hard we try to see the line in /cart.js before giving up (the verify is a read, not a write).
export const CART_VERIFY_RETRIES = 2;
export const CART_VERIFY_DELAY_MS = 250;
export const CART_TIMEOUT_MS = 8000;

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

// The filename a shared / downloaded look carries.
export const SHARE_FILENAME = 'trayon-look.jpg';

// Timers (ms). Boot is idle-scheduled; the observer is debounced; polling is bounded.
export const BOOT_IDLE_TIMEOUT_MS = 3000;
export const OBSERVER_DEBOUNCE_MS = 150;
export const POLL_INTERVAL_MS = 1500;
export const POLL_TIMEOUT_MS = 90000;
export const MAX_POLLS = Math.ceil(POLL_TIMEOUT_MS / POLL_INTERVAL_MS);
export const TOAST_MS = 3200;

// Internal widget events (dispatched on the shadow host element, not the host window).
export const EVENTS = {
  variantChanged: 'trayon:variant-changed',
  open: 'trayon:open',
};

// The event the Shopify Theme App Extension dispatches on `document` whenever the shopper
// switches variant (it keeps the tag's data-variant-id truthful across swatch clicks,
// ?variant=, popstate and DOM mutation). NOTE the name differs from our internal
// `trayon:variant-changed` by one letter — they are two different signals, on purpose.
export const HOST_VARIANT_EVENT = 'trayon:variant-change';

// The floating status HUD — the restyled `.ton-notification`. It is the shopper's only tether
// to a generation that is running while the modal is closed (and on pages with no trigger at
// all), which is exactly why it is CORE and never lazy.
export const HUD = {
  idle: 'idle',
  thinking: 'thinking',
  ready: 'ready',
  failed: 'failed',
  unavailable: 'unavailable',
};

// `failed` and `unavailable` share the one error skin the harness asserts on.
export const HUD_CLASS = {
  idle: 'ton-notification--idle',
  thinking: 'ton-notification--thinking',
  ready: 'ton-notification--ready',
  failed: 'ton-notification--error',
  unavailable: 'ton-notification--error',
};

// The gradient-filled sparkle is an SVG whose fill is url(#ton-grad). SVG ids are scoped per
// shadow root, so EVERY root that renders it must carry its own <linearGradient> def — a missing
// def is an invisible icon, the single most likely silent bug in the rebuild.
export const GRAD_ID = 'ton-grad';

// The CSS placeholder the shell rewrites to the absolute asset base at inject time. A <style>
// inside a shadow root resolves relative url()s against the HOST DOCUMENT, not against us — so
// the self-hosted webfont MUST be an absolute URL derived from the widget script's own origin.
export const ASSET_BASE_TOKEN = '__TON_ASSET_BASE__';

// The stylesheet keys the shell tracks so a sheet is only ever appended once per root.
export const CSS_KEY = { core: 'core', modal: 'modal', club: 'club' };

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

// The tracking interaction type emitted when a shopper joins the club (verifies their code).
export const TRACK_INTERACTION_CLUB_JOIN = 'club_join';

// --- Customer Club (banner + email OTP login + display-only member pricing) ------
// The BROWSER only ever holds the public anon_token + the shopper's own email; the OTP is
// issued/validated server-side (keyed on anon_token + email). No secret, no member PII beyond
// what the shopper types, and NO real cost/discount code ever flows here — the discount is
// DISPLAY-ONLY (checkout is unchanged). The club config arrives in the bootstrap `club` block.

// Club endpoints (behind the existing widget middleware group; typed JSON both ways).
export const CLUB_ENDPOINTS = {
  requestCode: '/club/request-code',
  verifyCode: '/club/verify-code',
};

// Club request/verify field names (mirror the ClubController FormRequests).
export const CLUB_FIELD = {
  anonToken: 'anon_token',
  email: 'email',
  code: 'code',
};

// Typed reasons the verify/request endpoints can return (rendered as friendly i18n states).
export const CLUB_REASON = {
  throttled: 'throttled', // request-code: a code was sent too recently
  sendFailed: 'send_failed', // request-code: the mail transport failed (SMTP) — retry
  invalid: 'invalid', // verify-code: wrong code
  expired: 'expired', // verify-code: the code's TTL passed
  locked: 'locked', // verify-code: too many wrong attempts
};

// The mini-form step ids (email -> code).
export const CLUB_STEP = { email: 'email', code: 'code' };

// The 6-digit OTP length (client-side input guard only; the server is the real validator).
export const CLUB_CODE_LENGTH = 6;

// Site-scoped localStorage key for the persisted verified-member flag (like the anon token).
// Value: '1' once verified. Scoped per site_key so two Vsio sites on one origin never collide.
export const STORAGE_CLUB_MEMBER_PREFIX = 'trayon.club.member.'; // + site_key

// Site-scoped localStorage key for a persisted banner DISMISSAL. Value: the epoch-ms instant the
// dismissal expires (now + banner_dismiss_days). While unexpired the join banner stays hidden even
// across reloads; a 0-day config never writes this key (session-only, reappears next load).
export const STORAGE_CLUB_DISMISS_PREFIX = 'trayon.club.dismissed.'; // + site_key

// The bootstrap `club` block keys (BootstrapController club shape).
export const CLUB_CONFIG = {
  enabled: 'enabled',
  discountPercent: 'discount_percent',
  priceZones: 'price_zones', // { pdp: string[], catalog: string[], cart: string[] }
  bannerTrigger: 'banner_trigger', // 'immediate' | 'delay' | 'scroll'
  bannerDelaySeconds: 'banner_delay_seconds', // when trigger==='delay'
  bannerScrollPercent: 'banner_scroll_percent', // when trigger==='scroll'
  bannerPosition: 'banner_position', // 'bottom-end' | 'bottom-start' | 'top-end' | 'top-start'
  bannerDismissDays: 'banner_dismiss_days', // how long a dismissal is remembered (0 = session-only)
  member: 'member', // { verified: bool }
};

// When the join banner appears (mirrors ClubConfig::BANNER_TRIGGERS).
export const CLUB_TRIGGER = { immediate: 'immediate', delay: 'delay', scroll: 'scroll' };

// The corner the banner sits in — LOGICAL sides so it mirrors in RTL (mirrors ClubConfig positions).
// The value doubles as the CSS modifier suffix: `ton-club-banner--<position>`.
export const CLUB_POSITION_DEFAULT = 'bottom-end';
export const CLUB_POSITIONS = ['bottom-end', 'bottom-start', 'top-end', 'top-start'];

// The price-zone surfaces (a union of all three is applied on whatever resolves on THIS page).
export const CLUB_ZONES = ['pdp', 'catalog', 'cart'];

// --- Merchant banners (runtime injection + per-banner analytics) ------------------------
// A banner is a merchant-authored, AI-generated image the widget injects at merchant-picked
// host-page spots, gated by client-side rules, with impression/click analytics. The bootstrap
// `banners` block carries each banner's public image url, placements, and client-evaluated rules
// (audience/pages/frequency/locale); the schedule was already enforced server-side.

// The bootstrap banner object keys (BootstrapController::bannersPayload shape).
export const BANNER_CONFIG = {
  id: 'id',
  composition: 'composition',
  imageUrl: 'image_url',
  width: 'width',
  height: 'height',
  targetUrl: 'target_url',
  alt: 'alt',
  overlay: 'overlay', // { headline, subtext, cta_label }
  placements: 'placements', // [{ selector, position }]
  rules: 'rules', // { audience, pages:{context,url_contains}, frequency:{max_per_session}, locales:[] }
};

export const BANNER_COMPOSITION = { image: 'image', overlay: 'overlay' };

export const BANNER_OVERLAY = { headline: 'headline', subtext: 'subtext', ctaLabel: 'cta_label' };

export const BANNER_PLACEMENT = { selector: 'selector', position: 'position' };

// Injection positions relative to a picked element (mirror WidgetAppearance POSITION values).
export const BANNER_PLACE = { before: 'before', after: 'after', prepend: 'prepend', append: 'append' };

// Rule keys + their value enums (mirror App\Domain\Banners\BannerRules).
export const BANNER_RULE = { audience: 'audience', pages: 'pages', frequency: 'frequency', locales: 'locales' };
export const BANNER_PAGE_KEY = { context: 'context', urlContains: 'url_contains' };
export const BANNER_FREQ_KEY = { max: 'max_per_session' };

export const BANNER_AUDIENCE = {
  any: 'any',
  clubMembers: 'club_members',
  nonMembers: 'non_members',
  registered: 'registered',
  newVisitors: 'new_visitors',
  returningVisitors: 'returning_visitors',
};

export const BANNER_PAGE = { any: 'any', pdp: 'pdp', catalog: 'catalog', cart: 'cart' };

// Analytics: the /banners/event body + kinds.
export const BANNER_KIND = { impression: 'impression', click: 'click' };
export const BANNER_EVENT_FIELD = { bannerId: 'banner_id', kind: 'kind', anonToken: 'anon_token', path: 'path' };

// A mounted banner's sentinel (value = banner id) — idempotent injection per banner + spot.
export const BANNER_SENTINEL_ATTR = 'data-trayon-banner';

// localStorage: whether this shopper has been seen before (new vs returning targeting). + site_key.
export const STORAGE_SEEN_PREFIX = 'trayon.seen.';

// sessionStorage: per-session impression counter for the frequency cap. + site_key + ':' + banner id.
export const SESSION_BANNER_IMPR_PREFIX = 'trayon.bimp.';

// Best-effort cart-page heuristic for the `cart` page-context rule (merchants refine via url_contains).
export const BANNER_CART_URL_RE = /\/(cart|checkout|basket|bag)(\/|$|\?|#)/i;

// A processed price node is marked so a re-apply (variant change) never double-discounts it.
// The ORIGINAL formatted text is stashed in a data-attr so a re-apply recomputes from source.
export const PRICING_MARK_ATTR = 'data-trayon-club-price';
export const PRICING_ORIGINAL_ATTR = 'data-trayon-orig-price';

// Structural class for the injected "club price" affordance appended after the rewritten price.
export const PRICING_BADGE_CLASS = 'trayon-club-badge';
