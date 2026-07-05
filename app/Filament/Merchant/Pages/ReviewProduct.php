<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Scan\Review\ConfirmGate;
use App\Domain\Scan\Review\ConfirmScanAction;
use App\Domain\Scan\Review\ConfirmScanInput;
use App\Domain\Scan\Review\ScanConfirmBlockedException;
use App\Domain\Scan\Review\ScanReview;
use App\Domain\Scan\Review\SelectorTester;
use App\Domain\Scan\ScanConstants;
use App\Models\Product;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

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

    // The selector roles the form drives (mirrors the contract's SELECTOR_ROLES).
    private const SELECTOR_ROLES = ScanConstants::SELECTOR_ROLES;

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
