<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Preview\PreviewFetcher;
use App\Domain\Scan\Preview\PreviewResult;
use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\Review\SelectorTester;
use App\Domain\Sites\ClubConfig;
use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\SiteSettingsService;
use App\Models\Product;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Customer-Club settings (Phase 2b-UI / 2c) — the per-shop screen where a merchant
 * enables the club, sets the member discount %, and VISUALLY picks where the member
 * price is displayed on their store: one or MORE price elements per surface
 * (PDP / catalog / cart). Binds 1:1 to SiteSettingsService::update($site, [
 * KEY_CLUB_CONFIG => …]) — the single validated writer, which routes the config
 * through ClubConfig::sanitize before persisting the one whitelisted column. A bad
 * value (out-of-range discount, a non-allow-listed selector, too many zones) throws
 * a typed InvalidSiteSettingsException (reason invalid_club_config) that this page
 * renders as a SOFT field error — never a 500, never a partial save.
 *
 * The visual picker REUSES the exact preview rail the placement + scan-review pickers
 * use (PreviewFetcher / PreviewSanitizer / PreviewSnapshotStore + the sandboxed
 * iframe + the picker.js in ZONE mode). PDP defaults to the merchant's most-recently
 * scanned product snapshot (no network); catalog + cart have no scan snapshot, so the
 * merchant pastes a URL and loads a live preview (the same SSRF-guarded fetcher).
 * Each pick is verified SERVER-SIDE (SelectorTester over the cached DOM; resolves-to-
 * one) before it is added to the surface's zone list — the picked selector is an
 * untrusted string, only ever counted as a DOM query, NEVER executed.
 *
 * Tenant-safety: the shop is the Filament tenant (Site::class); a super-admin drill-in
 * reads it the same way. The site resolves through the BelongsToAccount global scope
 * (no manual where(account_id), no withoutGlobalScopes()), and the preview cache key
 * is namespaced by the bound site id — so no cross-tenant read is possible.
 */
class ClubSettings extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.merchant.pages.club-settings';

    // The three storefront surfaces — mirrors ClubConfig::SURFACES (single source of truth).
    private const SURFACES = ClubConfig::SURFACES;

    // Per-surface zone cap — mirrors the backend so the UI never lets a merchant build
    // a config the service will reject.
    private const ZONES_MAX = ClubConfig::ZONES_PER_SURFACE_MAX;

    // Banner behavior option sets — mirror the backend enums (single source of truth).
    private const TRIGGERS = ClubConfig::BANNER_TRIGGERS;

    private const POSITIONS = ClubConfig::BANNER_POSITIONS;

    // i18n key prefixes for the behavior select labels (never a literal in the page).
    private const TRIGGER_OPTION_PREFIX = 'club.behavior.trigger_option.';

    private const POSITION_OPTION_PREFIX = 'club.behavior.position_option.';

    // The picker runs in ZONE mode here: each click accumulates another price element.
    private const PICKER_MODE_ZONE = 'zone';

    private const PICKER_ASSET = 'widget/picker/picker.js';

    // Preview cache + rate limit (mirrors WidgetAppearanceSettings) — headless renders
    // cost money, so cache briefly + cap the live "Load preview" attempts per site.
    private const PREVIEW_CACHE_TTL_MINUTES = 10;

    private const PREVIEW_RATE_MAX = 20;

    private const PREVIEW_RATE_DECAY = 60;

    // i18n keys — never a literal in the page.
    private const NAV_LABEL = 'club.settings.nav';

    private const TITLE = 'club.settings.title';

    private const SAVED = 'club.settings.saved';

    private const SAVE_FAILED = 'club.settings.errors.save_failed';

    private const ERROR_PREFIX = 'club.settings.errors.';

    private const PICK_LOAD_FAILED = 'club.zones.errors.load_failed';

    // Maps the discount field's Livewire prop for a mapped field error.
    private const DISCOUNT_FIELD = 'discountPercent';

    /** The bound shop id (scalar — Livewire-safe; the model re-resolves on demand). */
    public ?int $siteId = null;

    public bool $hasSite = false;

    // --- Form state (public Livewire props) ---
    public bool $enabled = false;

    /** Whole percent 0..100 (validated server-side by ClubConfig). */
    public int $discountPercent = 0;

    /**
     * Per-surface verified price-zone selectors: surface => list of selectors.
     *
     * @var array<string,array<int,string>>
     */
    public array $priceZones = [
        ClubConfig::SURFACE_PDP => [],
        ClubConfig::SURFACE_CATALOG => [],
        ClubConfig::SURFACE_CART => [],
    ];

    // --- Banner behavior + timing (hydrated from the resolved config; validated server-side). ---
    /** When the join banner appears: immediate | delay | scroll. */
    public string $bannerTrigger = ClubConfig::TRIGGER_IMMEDIATE;

    /** Seconds after load before the banner shows (when trigger = delay). */
    public int $bannerDelaySeconds = 0;

    /** Page scroll depth (%) that reveals the banner (when trigger = scroll). */
    public int $bannerScrollPercent = 0;

    /** Which corner the banner sits in (logical side, RTL-aware). */
    public string $bannerPosition = ClubConfig::POSITION_BOTTOM_END;

    /** How long a shopper's dismissal is remembered, in days (0 = session-only). */
    public int $bannerDismissDays = 0;

    // --- Visual zone-picker state (small scalars only; the preview HTML is cached). ---
    public bool $pickerOpen = false;

    /** Which surface the open picker is accumulating zones for. */
    public ?string $pickerSurface = null;

    public ?string $previewUrl = null;

    /** sha1(url) of the currently-cached preview; the srcdoc + verify read the cache by this. */
    public ?string $previewToken = null;

    public ?string $previewFinalUrl = null;

    /** Where the current preview came from: 'snapshot' (scanned PDP) or 'live' (manual URL). */
    public ?string $previewSource = null;

    public ?string $previewError = null;

    /** The last pick's verdict (ok / count / reason) for the panel. */
    public ?array $pickVerdict = null;

    public static function getNavigationLabel(): string
    {
        return __(self::NAV_LABEL);
    }

    public static function getNavigationGroup(): ?string
    {
        return __(self::$navigationGroup);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /** Bind the current shop (the Filament tenant) and hydrate the form from its config. */
    public function mount(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Site) {
            return;
        }

        $this->siteId = (int) $tenant->getKey();
        $this->hasSite = true;
        $this->hydrateFrom($tenant);
    }

    /** The bound shop (account-scoped), or null. */
    public function site(): ?Site
    {
        return $this->siteId !== null
            ? Site::query()->find($this->siteId)
            : null;
    }

    /** The three surfaces the merchant picks zones on (for the view loop). */
    public function surfaces(): array
    {
        return self::SURFACES;
    }

    /** The picker mode the preview iframe runs in (zone-mode here). */
    public function pickerMode(): string
    {
        return self::PICKER_MODE_ZONE;
    }

    /** Trigger options for the select: value => localized label. */
    public function triggerOptions(): array
    {
        return $this->optionLabels(self::TRIGGERS, self::TRIGGER_OPTION_PREFIX);
    }

    /** Banner-position options for the select: value => localized label. */
    public function positionOptions(): array
    {
        return $this->optionLabels(self::POSITIONS, self::POSITION_OPTION_PREFIX);
    }

    /** Map an enum value list to a value => __(prefix.value) label array. */
    private function optionLabels(array $values, string $prefix): array
    {
        $out = [];

        foreach ($values as $value) {
            $out[$value] = __($prefix.$value);
        }

        return $out;
    }

    /** The verified zones stored for a surface (for the summary + the picker echo). */
    public function zonesFor(string $surface): array
    {
        return in_array($surface, self::SURFACES, true)
            ? array_values($this->priceZones[$surface] ?? [])
            : [];
    }

    // === Visual zone-picker actions ===

    /**
     * Open the full-screen zone picker for one surface. For PDP the PRIMARY path
     * renders the merchant's most-recently scanned product straight from the stored
     * snapshot (no live fetch). Catalog + cart have no snapshot, so the stage opens
     * empty and the merchant pastes a URL to load a live preview. Fully guarded: any
     * failure opens with a soft message + the manual-URL fallback, never a 500.
     */
    public function openPicker(string $surface): void
    {
        if (! in_array($surface, self::SURFACES, true)) {
            return;
        }

        $this->previewError = null;
        $this->pickVerdict = null;
        $this->previewToken = null;
        $this->previewSource = null;
        $this->pickerSurface = $surface;

        try {
            if ($surface === ClubConfig::SURFACE_PDP) {
                $product = $this->latestScannedProduct();

                if ($product !== null) {
                    $this->previewUrl = $product->source_url;
                    $this->loadProductPreview($product);
                }
            }
        } catch (\Throwable $e) {
            Log::error('club zone-picker open failed', ['site_id' => $this->siteId, 'surface' => $surface, 'error' => $e->getMessage()]);
            $this->previewToken = null;
            $this->previewError = __(self::PICK_LOAD_FAILED);
        }

        $this->pickerOpen = true;
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
    }

    /**
     * Fetch + sanitize a live preview of the pasted URL (the SSRF-guarded scan fetcher),
     * cache it, and expose it to the sandboxed iframe. Rate-limited + fail-soft — the
     * catalog/cart fallback (no scan snapshot exists for those surfaces).
     */
    public function loadPreview(): void
    {
        $this->previewError = null;
        $this->previewToken = null;
        $this->pickVerdict = null;

        $url = trim((string) $this->previewUrl);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->previewError = __('club.zones.errors.bad_url');

            return;
        }

        $limiterKey = 'club-preview:'.(int) $this->siteId;

        if (RateLimiter::tooManyAttempts($limiterKey, self::PREVIEW_RATE_MAX)) {
            $this->previewError = __('club.zones.errors.rate_limited');

            return;
        }

        RateLimiter::hit($limiterKey, self::PREVIEW_RATE_DECAY);

        try {
            $preview = app(PreviewFetcher::class)->previewFor($url, $this->pickerScript());
        } catch (FetchException $e) {
            $this->previewError = $e->merchantMessage();

            return;
        } catch (\Throwable $e) {
            Log::warning('club zone-picker live preview failed', ['site_id' => $this->siteId, 'error' => $e->getMessage()]);
            $this->previewError = __(self::PICK_LOAD_FAILED);

            return;
        }

        $this->cachePreview($preview, $url);
    }

    /**
     * A merchant clicked an element in the preview for the open surface. Verify the
     * selector SERVER-SIDE against the cached DOM (resolves-to-one — the same predicate
     * SelectorTester/verifyPick use), and on a clean, unique match APPEND it to the
     * surface's zone list (deduped, capped at ZONES_MAX). A 0/N pick is flagged, never
     * stored. The picked selector is an untrusted string — only ever counted as a DOM
     * query here, NEVER executed.
     */
    public function pickZone(string $surface, string $selector): void
    {
        if ($surface !== $this->pickerSurface || ! in_array($surface, self::SURFACES, true)) {
            return;
        }

        $selector = trim($selector);
        $entry = $this->cachedPreview();

        if ($selector === '' || $entry === null) {
            $this->pickVerdict = ['ok' => false, 'count' => 0, 'reason' => 'none'];

            return;
        }

        $result = app(SelectorTester::class)->testAgainstDom($this->previewDom($entry), [$selector])[0] ?? null;
        $count = $result?->matchedCount ?? 0;

        $this->pickVerdict = [
            'ok' => $count === 1,
            'count' => $count,
            'reason' => $count === 1 ? 'added' : ($count === 0 ? 'none' : 'multiple'),
        ];

        if ($count !== 1) {
            return;
        }

        $zones = $this->zonesFor($surface);

        if (in_array($selector, $zones, true)) {
            $this->pickVerdict['reason'] = 'duplicate';

            return;
        }

        if (count($zones) >= self::ZONES_MAX) {
            $this->pickVerdict = ['ok' => false, 'count' => $count, 'reason' => 'full'];

            return;
        }

        $zones[] = $selector;
        $this->priceZones[$surface] = $zones;
    }

    /** Remove one verified zone from a surface (the summary chips' × button). */
    public function removeZone(string $surface, int $index): void
    {
        if (! in_array($surface, self::SURFACES, true)) {
            return;
        }

        $zones = $this->zonesFor($surface);

        if (array_key_exists($index, $zones)) {
            unset($zones[$index]);
            $this->priceZones[$surface] = array_values($zones);
        }
    }

    /** The sanitized preview HTML for the iframe srcdoc — read from cache, never a Livewire prop. */
    public function previewSrcdoc(): string
    {
        $entry = $this->cachedPreview();

        return $entry !== null ? (string) ($entry['sanitized'] ?? '') : '';
    }

    /**
     * Validate-then-persist via the service. A typed InvalidSiteSettingsException
     * (reason invalid_club_config) maps to a soft field error under the discount input;
     * any other throwable shows a generic save-failed notice. Never a 500, never a
     * partial save. On success, a saved notification.
     */
    public function save(): void
    {
        $site = $this->site();

        if ($site === null) {
            return;
        }

        try {
            app(SiteSettingsService::class)->update($site, [
                SiteSettingsService::KEY_CLUB_CONFIG => $this->config(),
            ]);

            Notification::make()->success()->title(__(self::SAVED))->send();
        } catch (InvalidSiteSettingsException $e) {
            // The whole club config validates before any write; surface the reason as a
            // soft error on the discount field (the one directly-editable numeric input).
            $this->addError(self::DISCOUNT_FIELD, __(self::ERROR_PREFIX.$e->reason));
        } catch (\Throwable) {
            Notification::make()->danger()->title(__(self::SAVE_FAILED))->send();
        }
    }

    /** Seed the form props from the site's current (resolved) club config. */
    private function hydrateFrom(Site $site): void
    {
        $resolved = ClubConfig::resolve($site->club_config);

        $this->enabled = (bool) $resolved[ClubConfig::KEY_ENABLED];
        $this->discountPercent = (int) $resolved[ClubConfig::KEY_DISCOUNT_PERCENT];

        foreach (self::SURFACES as $surface) {
            $this->priceZones[$surface] = array_values($resolved[ClubConfig::KEY_PRICE_ZONES][$surface] ?? []);
        }

        $this->bannerTrigger = (string) $resolved[ClubConfig::KEY_BANNER_TRIGGER];
        $this->bannerDelaySeconds = (int) $resolved[ClubConfig::KEY_BANNER_DELAY_SECONDS];
        $this->bannerScrollPercent = (int) $resolved[ClubConfig::KEY_BANNER_SCROLL_PERCENT];
        $this->bannerPosition = (string) $resolved[ClubConfig::KEY_BANNER_POSITION];
        $this->bannerDismissDays = (int) $resolved[ClubConfig::KEY_BANNER_DISMISS_DAYS];
    }

    /**
     * Build the club config patch the service validates. The discount is cast to int so
     * the service (the single validator) still rejects an out-of-range value — this page
     * never silently coerces a bad value into range.
     *
     * @return array<string,mixed>
     */
    private function config(): array
    {
        return [
            ClubConfig::KEY_ENABLED => $this->enabled,
            ClubConfig::KEY_DISCOUNT_PERCENT => (int) $this->discountPercent,
            ClubConfig::KEY_PRICE_ZONES => [
                ClubConfig::SURFACE_PDP => $this->zonesFor(ClubConfig::SURFACE_PDP),
                ClubConfig::SURFACE_CATALOG => $this->zonesFor(ClubConfig::SURFACE_CATALOG),
                ClubConfig::SURFACE_CART => $this->zonesFor(ClubConfig::SURFACE_CART),
            ],
            // Behavior/timing — the service (single validator) rejects a bad enum / out-of-range
            // int; casting here never coerces a bad value silently into range.
            ClubConfig::KEY_BANNER_TRIGGER => (string) $this->bannerTrigger,
            ClubConfig::KEY_BANNER_DELAY_SECONDS => (int) $this->bannerDelaySeconds,
            ClubConfig::KEY_BANNER_SCROLL_PERCENT => (int) $this->bannerScrollPercent,
            ClubConfig::KEY_BANNER_POSITION => (string) $this->bannerPosition,
            ClubConfig::KEY_BANNER_DISMISS_DAYS => (int) $this->bannerDismissDays,
        ];
    }

    /** Load the picker preview from a scanned product's stored snapshot (no network). */
    private function loadProductPreview(Product $product): void
    {
        $html = app(PreviewSnapshotStore::class)->get($product);

        if ($html === null) {
            // No snapshot yet — leave the stage empty; the merchant can still load a
            // URL live via the fallback, or pick another surface.
            $this->previewToken = null;
            $this->previewSource = null;

            return;
        }

        $preview = app(PreviewFetcher::class)->previewFromHtml($html, (string) $product->source_url, $this->pickerScript());
        $this->cachePreview($preview, (string) $product->source_url, 'snapshot');
    }

    /** Cache the sanitized + raw preview + expose it to the iframe (snapshot + live paths). */
    private function cachePreview(PreviewResult $preview, string $url, string $source = 'live'): void
    {
        $token = sha1($url);

        Cache::put($this->previewCacheKey($token), [
            'sanitized' => $preview->sanitizedHtml,
            'raw' => $preview->rawHtml,
            'final_url' => $preview->finalUrl,
        ], now()->addMinutes(self::PREVIEW_CACHE_TTL_MINUTES));

        $this->previewToken = $token;
        $this->previewFinalUrl = $preview->finalUrl;
        $this->previewSource = $source;
    }

    /** The cached preview entry for the current token (site-scoped by the key), or null. */
    private function cachedPreview(): ?array
    {
        if ($this->previewToken === null) {
            return null;
        }

        $entry = Cache::get($this->previewCacheKey($this->previewToken));

        return is_array($entry) ? $entry : null;
    }

    /** A ScanDom over the cached raw preview HTML — the DOM picks are verified against. */
    private function previewDom(array $entry): ScanDom
    {
        return ScanDom::fromHtml((string) ($entry['raw'] ?? ''), (string) ($entry['final_url'] ?? ''));
    }

    /** Cache key namespaced by the merchant's OWN site id — no cross-tenant read possible. */
    private function previewCacheKey(string $token): string
    {
        return 'club_preview:'.(int) $this->siteId.':'.$token;
    }

    /**
     * The merchant's most-recently scanned product for this shop (confirmed preferred,
     * else a draft) — the PDP the picker previews. Account-scoped by BelongsToAccount.
     */
    private function latestScannedProduct(): ?Product
    {
        return Product::query()
            ->where('site_id', (int) $this->siteId)
            ->whereIn('status', [Product::STATUS_CONFIRMED, Product::STATUS_DRAFT])
            ->orderByRaw("CASE status WHEN '".Product::STATUS_CONFIRMED."' THEN 0 ELSE 1 END")
            ->latest('id')
            ->first();
    }

    /** The in-iframe picker script, inlined into the sanitized preview (read once). */
    private function pickerScript(): string
    {
        static $script = null;

        if ($script === null) {
            $path = resource_path(self::PICKER_ASSET);
            $script = is_file($path) ? (string) file_get_contents($path) : '';
        }

        return $script;
    }
}
