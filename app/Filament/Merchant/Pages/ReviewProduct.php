<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Scan\Preview\PreviewFetcher;
use App\Domain\Scan\Preview\PreviewResult;
use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Scan\Review\ConfirmGate;
use App\Domain\Scan\Review\ConfirmScanAction;
use App\Domain\Scan\Review\ConfirmScanInput;
use App\Domain\Scan\Review\DimensionPicker;
use App\Domain\Scan\Review\ScanConfirmBlockedException;
use App\Domain\Scan\Review\ScanReview;
use App\Domain\Scan\Review\SelectorTester;
use App\Domain\Scan\ScanConstants;
use App\Models\Product;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * M3 / A4 — the scan-review form. Binds 1:1 to ScanReview::fromProduct() (the
 * immutable read model) and the ConfirmGate (the no-auto-approve predicate). The
 * page holds NO scan logic: it renders the contract's rows, lets the merchant
 * correct values + selectors + acknowledge blocking rows, tests selectors via
 * SelectorTester, and confirms via ConfirmScanAction — which RE-EVALUATES the
 * gate server-side, so a crafted request can never confirm an unreviewed scan.
 *
 * Tenant-safety: Product/Site are BelongsToAccount and the panel is bound to the
 * owner's account (BindMerchantAccount), so findOrFail is already account-scoped.
 * There is NO manual where(account_id) and NO withoutGlobalScopes() here.
 */
class ReviewProduct extends Page
{
    // === CONSTANTS ===
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string $view = 'filament.merchant.pages.review-product';

    // i18n keys — never a literal in the page.
    private const TITLE = 'scan.title';
    private const NOTIFY_CONFIRMED = 'scan.confirmed';
    private const NOTIFY_BLOCKED = 'scan.blocked.reason';
    private const NOTIFY_TEST_ERROR = 'scan.selector.test_error';
    private const PICK_ERROR = 'scan.pick.errors.load_failed';

    // The selector roles the form drives (mirrors the contract's SELECTOR_ROLES).
    private const SELECTOR_ROLES = ScanConstants::SELECTOR_ROLES;

    // The physical-dimension roles the merchant may visually pick (size / weight).
    // A SEPARATE set from SELECTOR_ROLES on purpose — see ScanConstants::DIMENSION_ROLES.
    private const DIMENSION_ROLES = ScanConstants::DIMENSION_ROLES;

    // Picker-preview cache: the sanitized snapshot is cached by the product's OWN
    // account/site scope, so no cross-tenant read is possible (mirrors WidgetAppearanceSettings).
    private const PREVIEW_CACHE_TTL_MINUTES = 10;

    private const PICKER_ASSET = 'widget/picker/picker.js';

    // The picker runs in role-mode here: a click marks WHERE a detail is read from.
    private const PICKER_MODE_ROLE = 'role';

    /** The bound site + product ids (scalars — Livewire-safe; models resolve on
        demand through the account-scoped global scope, never as serialized props). */
    public int $siteId;

    public int $productId;

    /** Per-request memoised model handles (not Livewire state). */
    private ?Site $siteModel = null;

    private ?Product $productModel = null;

    /** Form state: corrected field values, keyed by writable product column. */
    public array $fieldValues = [];

    /** Form state: chosen selector per role (detected default or manual override). */
    public array $selectors = [];

    /** The blocking-row identifiers ("field:price") the merchant has acknowledged. */
    public array $reviewedKeys = [];

    /** Per-role last test outcome (the SelectorTestResult toArray() shape). */
    public array $testResults = [];

    /** The role currently being tested (drives the per-row spinner). */
    public ?string $testingRole = null;

    /**
     * Dimension picks: role => ['selector' => …, 'value' => …]. The merchant
     * visually marks where size/weight are shown; the value is read server-side.
     * @var array<string,array{selector:string,value:?string}>
     */
    public array $dimensionPicks = [];

    // --- Visual role-picker state (mirrors WidgetAppearanceSettings; small scalars only). ---
    public bool $pickerOpen = false;

    /** Which role the open picker is targeting (a selector role OR a dimension role). */
    public ?string $pickerRole = null;

    /** True when the open picker targets a dimension role (size/weight), not a selector role. */
    public bool $pickerIsDimension = false;

    /** sha1 token of the currently-cached preview snapshot; the srcdoc + verify read by it. */
    public ?string $previewToken = null;

    public ?string $previewFinalUrl = null;

    public ?string $previewError = null;

    /** The last pick's verdict shape (ok / count / value) for the panel. */
    public ?array $pickVerdict = null;

    /**
     * The custom route carries the site + product. A merchant reaches it from the
     * site's products list. Both are account-scoped by the global scope.
     */
    public static function getRoutePath(): string
    {
        return '/sites/{site}/products/{product}/review';
    }

    /** Resolve the records tenant-scoped, then hydrate the form from the contract. */
    public function mount(int|string $site, int|string $product): void
    {
        // findOrFail runs through the BelongsToAccount global scope (panel-bound
        // tenant), so a foreign account's id 404s — no manual account filter. The
        // ids alone are stored as Livewire state; the models resolve on demand.
        $this->siteId = (int) $this->site($site)->getKey();
        $this->productId = (int) $this->product($product)->getKey();

        $this->hydrateFromReview();
    }

    /** The bound site, account-scoped + memoised. */
    public function site(int|string|null $id = null): Site
    {
        return $this->siteModel ??= Site::query()->findOrFail($id ?? $this->siteId);
    }

    /** The bound product (with variants), account-scoped + memoised. */
    public function product(int|string|null $id = null): Product
    {
        return $this->productModel ??= Product::query()
            ->with('variants')
            ->findOrFail($id ?? $this->productId);
    }

    public function getTitle(): string|Htmlable
    {
        return __(self::TITLE);
    }

    /**
     * Deep-link to the widget-appearance visual placement picker, pre-opened on this
     * just-scanned product (?pick=1). This is the "scan a product → place the button on
     * your page" hand-off shown once the scan is confirmed.
     */
    public function placeButtonUrl(): string
    {
        return WidgetAppearanceSettings::getUrl(['site' => $this->siteId, 'pick' => 1]);
    }

    /** The fresh read model built from the (possibly just-confirmed) product. */
    public function review(): ScanReview
    {
        return ScanReview::fromProduct($this->product());
    }

    /**
     * Seed the editable form state from the contract's rows: each field's current
     * value, each selector's chosen value (the detected primary by default).
     */
    private function hydrateFromReview(): void
    {
        $review = $this->review();

        foreach ($review->fieldRows as $row) {
            if (in_array($row->key, ConfirmScanInput::WRITABLE_PRODUCT_COLUMNS, true) && is_scalar($row->value)) {
                $this->fieldValues[$row->key] = $row->value;
            }
        }

        // Map the scalar product columns the form edits to their backing column.
        $product = $this->product();
        $this->fieldValues['name'] ??= $product->name;
        $this->fieldValues['description'] ??= $product->description;
        $this->fieldValues['product_type'] ??= $product->product_type;
        $this->fieldValues['main_image_url'] ??= $product->main_image_url;

        foreach ($review->selectorRows as $row) {
            $this->selectors[$row->key] ??= is_string($row->value) ? $row->value : '';
        }

        $this->seedDimensionPicks($product);
    }

    /**
     * Seed the dimension-pick state from any previously-persisted picks under
     * physical_dimensions.picks, so a re-visit shows the merchant's earlier marks.
     * NOT named hydrate* — Livewire treats a hydrate*-prefixed method as a lifecycle
     * hook and would try to route-model-bind the Product parameter every request.
     */
    private function seedDimensionPicks(Product $product): void
    {
        $picks = ($product->physical_dimensions ?? [])[ScanConstants::DIMENSION_PICKS_KEY] ?? [];

        foreach (self::DIMENSION_ROLES as $role) {
            $pick = is_array($picks[$role] ?? null) ? $picks[$role] : [];

            $this->dimensionPicks[$role] ??= [
                ScanConstants::DIMENSION_PICK_SELECTOR => (string) ($pick[ScanConstants::DIMENSION_PICK_SELECTOR] ?? ''),
                ScanConstants::DIMENSION_PICK_VALUE => $pick[ScanConstants::DIMENSION_PICK_VALUE] ?? null,
            ];
        }
    }

    /**
     * Acknowledge a blocking row ("{kind}:{key}"). Toggles it in the reviewed set;
     * the gate (read live in the view) reopens once every blocking row is acked.
     */
    public function markReviewed(string $identifier): void
    {
        if (in_array($identifier, $this->reviewedKeys, true)) {
            $this->reviewedKeys = array_values(array_diff($this->reviewedKeys, [$identifier]));

            return;
        }

        $this->reviewedKeys[] = $identifier;
    }

    /** True when the merchant has acknowledged a given row identifier. */
    public function isReviewed(string $identifier): bool
    {
        return in_array($identifier, $this->reviewedKeys, true);
    }

    /**
     * Test one selector against the live page via SelectorTester. The typed
     * SelectorTestResult is stored for the row to render (test_ok / test_multiple /
     * test_fail / test_error). A fetch failure is an OUTCOME_ERROR, never a 500.
     */
    public function testSelector(string $role): void
    {
        if (! in_array($role, self::SELECTOR_ROLES, true)) {
            return;
        }

        $selector = trim((string) ($this->selectors[$role] ?? ''));

        if ($selector === '') {
            return;
        }

        $this->testingRole = $role;

        try {
            $results = app(SelectorTester::class)->testAgainstLivePage(
                $this->product()->source_url,
                [$selector],
            );

            $this->testResults[$role] = $results[0]?->toArray();
        } catch (\Throwable $e) {
            // The tester already maps fetch failures to OUTCOME_ERROR; this guards
            // any unexpected throwable into the same merchant-facing error line.
            $this->testResults[$role] = [
                'outcome' => 'error',
                'i18n_key' => self::NOTIFY_TEST_ERROR,
                'matched_count' => 0,
            ];
        } finally {
            $this->testingRole = null;
        }
    }

    /**
     * Open the visual picker on this product's stored page snapshot, in ROLE mode,
     * targeting one role (a selector role like `price`, or a dimension role like
     * `size`). REUSES the exact preview rail the placement picker uses:
     * PreviewSnapshotStore (the scan already stored the page) → PreviewFetcher::
     * previewFromHtml → PreviewSanitizer → the sandboxed iframe. No live fetch, no
     * SSRF surface. Fully guarded: any failure opens with a soft message, never a 500.
     */
    public function openRolePicker(string $role): void
    {
        $isSelector = in_array($role, self::SELECTOR_ROLES, true);
        $isDimension = in_array($role, self::DIMENSION_ROLES, true);

        if (! $isSelector && ! $isDimension) {
            return;
        }

        $this->previewError = null;
        $this->pickVerdict = null;
        $this->pickerRole = $role;
        $this->pickerIsDimension = $isDimension;

        try {
            $this->loadSnapshotPreview($this->product());
        } catch (\Throwable $e) {
            Log::warning('scan role-picker open failed', ['product_id' => $this->productId, 'role' => $role, 'error' => $e->getMessage()]);
            $this->previewToken = null;
            $this->previewError = __(self::PICK_ERROR);
        }

        $this->pickerOpen = true;
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
    }

    /**
     * A merchant picked an element in the preview for the open role. Verify the
     * selector SERVER-SIDE against the cached snapshot DOM (resolves-to-one is the
     * same predicate SelectorTester/verifyPick use), then:
     *  - for a SELECTOR role: fill that role's manual input (selectors.{role});
     *  - for a DIMENSION role: read the element's value and stage the pick.
     * The picked selector is an untrusted string — it is only ever verified as a DOM
     * query count here and (for dimensions) used to read text; it is NEVER executed.
     */
    public function pickRole(string $role, string $selector): void
    {
        if ($role !== $this->pickerRole) {
            return;
        }

        $selector = trim($selector);
        $entry = $this->cachedPreview();

        if ($selector === '' || $entry === null) {
            $this->pickVerdict = ['ok' => false, 'count' => 0, 'value' => null];

            return;
        }

        if (in_array($role, self::DIMENSION_ROLES, true)) {
            $this->pickDimension($role, $selector, $entry);

            return;
        }

        $this->pickSelector($role, $selector);
    }

    /** Verify a picked runtime-selector role + fill its manual input on a clean match. */
    private function pickSelector(string $role, string $selector): void
    {
        $results = app(SelectorTester::class)->testAgainstDom(
            $this->previewDom(),
            [$selector],
        );

        $result = $results[0] ?? null;
        $count = $result?->matchedCount ?? 0;

        // Reflect the verdict in the same testResults slot the "Test selector" row reads.
        if ($result !== null) {
            $this->testResults[$role] = $result->toArray();
        }

        $this->pickVerdict = ['ok' => $count === 1, 'count' => $count, 'value' => null];

        // Only a clean, unique match fills the manual input — a 0/N pick is flagged, not stored.
        if ($count === 1) {
            $this->selectors[$role] = $selector;
        }
    }

    /** Verify a picked dimension role, read its value, and stage it for confirm. */
    private function pickDimension(string $role, string $selector, array $entry): void
    {
        $result = app(DimensionPicker::class)->pick($this->previewDom(), $role, $selector);

        $this->pickVerdict = $result->toArray();

        // Stage the pick regardless of count so the row shows what was marked; the
        // value is null unless it resolved to exactly one element.
        $this->dimensionPicks[$role] = [
            ScanConstants::DIMENSION_PICK_SELECTOR => $selector,
            ScanConstants::DIMENSION_PICK_VALUE => $result->value,
        ];
    }

    /** The sanitized preview HTML for the iframe srcdoc — read from cache, never a Livewire prop. */
    public function previewSrcdoc(): string
    {
        $entry = $this->cachedPreview();

        return $entry !== null ? (string) ($entry['sanitized'] ?? '') : '';
    }

    /** Build a preview from the product's stored raw HTML snapshot (no network). */
    private function loadSnapshotPreview(Product $product): void
    {
        $html = app(PreviewSnapshotStore::class)->get($product);

        if ($html === null) {
            // No snapshot (older scan / storage unavailable) — the merchant can still
            // type + test a selector manually; the picker stage just shows empty.
            $this->previewToken = null;
            $this->previewError = __('scan.pick.errors.no_snapshot');

            return;
        }

        $preview = app(PreviewFetcher::class)->previewFromHtml(
            $html,
            (string) $product->source_url,
            $this->pickerScript(),
        );

        $this->cachePreview($preview, (string) $product->source_url);
    }

    /** Cache the sanitized + raw preview for the sandboxed iframe + server-side verify. */
    private function cachePreview(PreviewResult $preview, string $url): void
    {
        $token = sha1($url);

        Cache::put($this->previewCacheKey($token), [
            'sanitized' => $preview->sanitizedHtml,
            'raw' => $preview->rawHtml,
            'final_url' => $preview->finalUrl,
        ], now()->addMinutes(self::PREVIEW_CACHE_TTL_MINUTES));

        $this->previewToken = $token;
        $this->previewFinalUrl = $preview->finalUrl;
    }

    /** The cached preview entry for the current token, or null when expired/absent. */
    private function cachedPreview(): ?array
    {
        if ($this->previewToken === null) {
            return null;
        }

        $entry = Cache::get($this->previewCacheKey($this->previewToken));

        return is_array($entry) ? $entry : null;
    }

    /** A ScanDom over the cached raw preview HTML — the DOM picks are verified against. */
    private function previewDom(): ScanDom
    {
        $entry = $this->cachedPreview() ?? [];

        return ScanDom::fromHtml((string) ($entry['raw'] ?? ''), (string) ($entry['final_url'] ?? ''));
    }

    /**
     * Cache key namespaced by the product's OWN account + site + id, derived from the
     * bound (account-scoped) product — never a request value, so no cross-tenant read.
     */
    private function previewCacheKey(string $token): string
    {
        $product = $this->product();

        return 'scan_preview:'.(int) $product->account_id.':'.(int) $product->site_id.':'.(int) $product->getKey().':'.$token;
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

    /** The picker mode the preview iframe runs in (role-mode here). */
    public function pickerMode(): string
    {
        return self::PICKER_MODE_ROLE;
    }

    /**
     * Confirm the product. Builds ConfirmScanInput from the form state and calls
     * ConfirmScanAction::confirm() — which re-runs the gate SERVER-SIDE. A still-
     * blocked confirm surfaces scan.blocked.reason gracefully (no exception leak).
     */
    public function confirm(): void
    {
        $input = ConfirmScanInput::fromArray([
            'fields' => $this->fieldValues,
            'selectors' => $this->selectors,
            'variants' => [],
            'reviewed_keys' => $this->reviewedKeys,
            'dimension_picks' => $this->dimensionPicks,
        ]);

        try {
            // Re-point the memoised model to the confirmed snapshot so the view
            // re-renders the now-live product without a stale read.
            $this->productModel = app(ConfirmScanAction::class)->confirm($this->product(), $input);

            Notification::make()
                ->title(__(self::NOTIFY_CONFIRMED))
                ->success()
                ->send();
        } catch (ScanConfirmBlockedException $e) {
            Notification::make()
                ->title(__(self::NOTIFY_BLOCKED))
                ->danger()
                ->send();
        }
    }

    /** The current gate, evaluated over the live rows + the acknowledged set. */
    public function gate(): ConfirmGate
    {
        return ConfirmGate::evaluate($this->review()->rows(), $this->reviewedKeys);
    }

    /** Inject the read model + the live gate into the page view. */
    protected function getViewData(): array
    {
        return [
            'review' => $this->review(),
            'gate' => $this->gate(),
        ];
    }
}
