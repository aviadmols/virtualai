<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Banners\BannerPlacements as PlacementSchema;
use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Domain\Media\MediaStorage;
use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Preview\PreviewFetcher;
use App\Domain\Scan\Preview\PreviewResult;
use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\Review\SelectorTester;
use App\Models\Banner;
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
 * Banner placement picker (Phase 3) — the per-banner screen where a merchant VISUALLY marks the
 * host-page spots the banner is injected at. It REUSES the exact sandboxed-iframe preview rail the
 * Club price-zone + button-placement pickers use (PreviewFetcher / PreviewSanitizer /
 * PreviewSnapshotStore + the sandboxed iframe + picker.js in ZONE mode). The store preview defaults
 * to the merchant's most-recently scanned product snapshot (no network); a live URL is the fallback.
 * Each pick is verified SERVER-SIDE (SelectorTester over the cached DOM; resolves-to-one) before it
 * enters the banner's placement list — the picked selector is untrusted, only ever COUNTED as a DOM
 * query, NEVER executed. Stored as { selector, position } via the single validated writer
 * (BannerService::updatePlacements → BannerPlacements::sanitize).
 *
 * Tenant-safety: bound to the shop (Filament tenant, Site); the banner is loaded scoped to that
 * shop; the preview cache key is namespaced by the bound site id — no cross-tenant read is possible.
 */
class BannerPlacements extends Page
{
    // === CONSTANTS ===
    protected static bool $shouldRegisterNavigation = false; // reached from the banner editor

    protected static ?string $slug = 'banner-placements';

    protected static string $view = 'filament.merchant.pages.banner-placements';

    // The picker runs in ZONE mode here: each click accumulates another placement.
    private const PICKER_MODE_ZONE = 'zone';

    private const PICKER_ASSET = 'widget/picker/picker.js';

    // Preview cache + rate limit (mirrors ClubSettings) — headless renders cost money.
    private const PREVIEW_CACHE_TTL_MINUTES = 10;
    private const PREVIEW_RATE_MAX = 20;
    private const PREVIEW_RATE_DECAY = 60;

    private const POSITION_OPTION_PREFIX = 'banners.placements.position_option.';

    private const TITLE = 'banners.placements.title';
    private const SAVED = 'banners.saved';
    private const SAVE_FAILED = 'banners.errors.save_failed';
    private const PICK_LOAD_FAILED = 'banners.placements.errors.load_failed';

    /** The bound shop id + the banner id (scalars; the models re-resolve on demand). */
    public ?int $siteId = null;

    public bool $hasSite = false;

    public ?int $bannerId = null;

    public bool $hasBanner = false;

    /**
     * The working placement list: a list of { selector, position }.
     * @var array<int,array{selector:string,position:string}>
     */
    public array $placements = [];

    // --- Visual picker state (small scalars only; the preview HTML is cached). ---
    public bool $pickerOpen = false;

    public ?string $previewUrl = null;

    public ?string $previewToken = null;

    public ?string $previewFinalUrl = null;

    public ?string $previewSource = null;

    public ?string $previewError = null;

    /** The last pick's verdict (ok / count / reason) for the panel. */
    public ?array $pickVerdict = null;

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /** Bind the shop (Filament tenant) + the banner (from ?banner=), scoped to the shop. */
    public function mount(?int $banner = null): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Site) {
            return;
        }

        $this->siteId = (int) $tenant->getKey();
        $this->hasSite = true;

        // The banner id arrives as the ?banner= query param (the editor's link); a test may pass
        // it directly. Either way the banner is loaded SCOPED to the bound shop.
        $bannerId = (int) ($banner ?? request()->query('banner', 0));
        $banner = $bannerId > 0
            ? Banner::query()->where('site_id', $this->siteId)->find($bannerId)
            : null;

        if ($banner === null) {
            return;
        }

        $this->bannerId = (int) $banner->getKey();
        $this->hasBanner = true;
        $this->placements = $this->normalizePlacements($banner->placements ?? []);
    }

    /** The bound banner (shop-scoped), or null. */
    public function banner(): ?Banner
    {
        return $this->hasBanner
            ? Banner::query()->where('site_id', (int) $this->siteId)->find($this->bannerId)
            : null;
    }

    public function pickerMode(): string
    {
        return self::PICKER_MODE_ZONE;
    }

    /**
     * The selected artwork's PUBLIC URL — rendered as the real banner at each picked spot in the
     * preview (WYSIWYG). Null when no candidate has been chosen yet; the picker then shows a
     * numbered placeholder block instead.
     */
    public function bannerImageUrl(): ?string
    {
        $banner = $this->banner();

        return $banner !== null ? app(MediaStorage::class)->publicUrl($banner->image_path) : null;
    }

    /** Position value => localized label (for the per-placement select). */
    public function positionOptions(): array
    {
        $out = [];
        foreach (PlacementSchema::POSITIONS as $value) {
            $out[$value] = __(self::POSITION_OPTION_PREFIX.$value);
        }

        return $out;
    }

    /** The picked selectors (for the picker's setZones echo — the confirmed highlight set). */
    public function placementSelectors(): array
    {
        return array_values(array_map(static fn (array $p): string => $p['selector'], $this->placements));
    }

    // === Visual picker actions ===

    /** Open the picker. Default preview = the shop's most-recently scanned product snapshot. */
    public function openPicker(): void
    {
        $this->previewError = null;
        $this->pickVerdict = null;
        $this->previewToken = null;
        $this->previewSource = null;

        try {
            $product = $this->latestScannedProduct();

            if ($product !== null) {
                $this->previewUrl = $product->source_url;
                $this->loadProductPreview($product);
            }
        } catch (\Throwable $e) {
            Log::error('banner placement picker open failed', ['site_id' => $this->siteId, 'error' => $e->getMessage()]);
            $this->previewToken = null;
            $this->previewError = __(self::PICK_LOAD_FAILED);
        }

        $this->pickerOpen = true;
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
    }

    /** Fetch + sanitize a live preview of the pasted URL (SSRF-guarded), cache it, expose it. */
    public function loadPreview(): void
    {
        $this->previewError = null;
        $this->previewToken = null;
        $this->pickVerdict = null;

        $url = trim((string) $this->previewUrl);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->previewError = __('banners.placements.errors.bad_url');

            return;
        }

        $limiterKey = 'banner-preview:'.(int) $this->siteId;

        if (RateLimiter::tooManyAttempts($limiterKey, self::PREVIEW_RATE_MAX)) {
            $this->previewError = __('banners.placements.errors.rate_limited');

            return;
        }

        RateLimiter::hit($limiterKey, self::PREVIEW_RATE_DECAY);

        try {
            $preview = app(PreviewFetcher::class)->previewFor($url, $this->pickerScript());
        } catch (FetchException $e) {
            $this->previewError = $e->merchantMessage();

            return;
        } catch (\Throwable $e) {
            Log::warning('banner placement live preview failed', ['site_id' => $this->siteId, 'error' => $e->getMessage()]);
            $this->previewError = __(self::PICK_LOAD_FAILED);

            return;
        }

        $this->cachePreview($preview, $url);
    }

    /**
     * A merchant clicked an element in the preview. Verify the selector SERVER-SIDE against the
     * cached DOM (resolves-to-one) and, on a clean unique match, APPEND it as a { selector,
     * position:'after' } placement (deduped by selector, capped at MAX). A 0/N pick is flagged,
     * never stored. The selector is only ever COUNTED as a DOM query here, NEVER executed.
     */
    public function pickPlacement(string $selector): void
    {
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

        if (in_array($selector, $this->placementSelectors(), true)) {
            $this->pickVerdict['reason'] = 'duplicate';

            return;
        }

        if (count($this->placements) >= PlacementSchema::MAX) {
            $this->pickVerdict = ['ok' => false, 'count' => $count, 'reason' => 'full'];

            return;
        }

        $this->placements[] = ['selector' => $selector, 'position' => PlacementSchema::POSITION_DEFAULT];
    }

    /** Remove one placement (the summary chips' × button). */
    public function removePlacement(int $index): void
    {
        if (array_key_exists($index, $this->placements)) {
            unset($this->placements[$index]);
            $this->placements = array_values($this->placements);
        }
    }

    /** The sanitized preview HTML for the iframe srcdoc — read from cache, never a Livewire prop. */
    public function previewSrcdoc(): string
    {
        $entry = $this->cachedPreview();

        return $entry !== null ? (string) ($entry['sanitized'] ?? '') : '';
    }

    /** Validate-then-persist via the single writer. A typed rejection is a soft error, never a 500. */
    public function save(): void
    {
        $banner = $this->banner();

        if ($banner === null) {
            return;
        }

        try {
            app(BannerService::class)->updatePlacements($banner, $this->placements);
            $this->placements = $this->normalizePlacements($banner->fresh()->placements ?? []);
            Notification::make()->success()->title(__(self::SAVED))->send();
        } catch (InvalidBannerException $e) {
            Notification::make()->danger()->title(__('banners.errors.'.$e->reason))->send();
        } catch (\Throwable) {
            Notification::make()->danger()->title(__(self::SAVE_FAILED))->send();
        }
    }

    /** Coerce a stored placements blob into the working [{selector, position}] shape. */
    private function normalizePlacements(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $p) {
            if (is_array($p) && isset($p['selector'])) {
                $out[] = [
                    'selector' => (string) $p['selector'],
                    'position' => (string) ($p['position'] ?? PlacementSchema::POSITION_DEFAULT),
                ];
            }
        }

        return $out;
    }

    /** Load the picker preview from a scanned product's stored snapshot (no network). */
    private function loadProductPreview(Product $product): void
    {
        $html = app(PreviewSnapshotStore::class)->get($product);

        if ($html === null) {
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
        return 'banner_preview:'.(int) $this->siteId.':'.$token;
    }

    /** The shop's most-recently scanned product (confirmed preferred, else a draft), or null. */
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
