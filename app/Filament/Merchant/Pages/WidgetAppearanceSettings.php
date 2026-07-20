<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Scan\Fetch\FetchException;
use App\Domain\Scan\Preview\PreviewFetcher;
use App\Domain\Scan\Preview\PreviewResult;
use App\Domain\Scan\Preview\PreviewSnapshotStore;
use App\Domain\Scan\Represent\ScanDom;
use App\Domain\Sites\ButtonVisibility;
use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\SiteSettingsService;
use App\Domain\Sites\WidgetAppearance;
use App\Models\Product;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-site widget appearance — where the Vsio button sits, its text + colours, and the try-on
 * popup's theme + accent. Binds 1:1 to SiteSettingsService::update() (the single validated writer)
 * which routes the appearance through WidgetAppearance::sanitize before persisting the one
 * whitelisted column; the storefront widget reads the resolved values from the bootstrap API.
 *
 * Placement is chosen with the VISUAL PICKER (this page's full-screen overlay): the merchant loads
 * a live preview of a product URL and clicks any element to place the button. The pick is verified
 * server-side (SelectorVerifier via ScanDom) and stored as button_placement=custom + a host anchor
 * selector + a position; the legacy presets remain as the runtime fallback. Tenant-safe: the site
 * resolves through the account-bound scope; the preview fetch reuses the SSRF-guarded scan fetcher.
 */
class WidgetAppearanceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.merchant.pages.widget-appearance-settings';

    private const NAV_LABEL = 'appearance.nav';

    private const TITLE = 'appearance.title';

    private const SAVED = 'appearance.saved';

    private const SAVE_FAILED = 'appearance.errors.save_failed';

    // Preview cache + rate limit — headless renders cost money, so cache briefly + cap per site.
    private const PREVIEW_CACHE_TTL_MINUTES = 10;

    private const PREVIEW_RATE_MAX = 20;   // "Load preview" attempts per window, per site

    private const PREVIEW_RATE_DECAY = 60; // seconds

    private const PICKER_ASSET = 'widget/picker/picker.js';

    // Button-visibility rule form fields — persisted to the SEPARATE button_rules column
    // (ButtonVisibility), never mixed into the appearance blob. Only Shopify sites carry the
    // tag/collection metadata the tag/collection modes need, so the section is Shopify-only.
    private const FIELD_RULE_MODE = 'button_rule_mode';

    private const FIELD_RULE_VALUES = 'button_rule_values';

    /** The bound site id (scalar — Livewire-safe; the model re-resolves on demand). */
    public ?int $siteId = null;

    public bool $hasSite = false;

    /** @var array<string,mixed> */
    public ?array $data = [];

    // --- Visual placement picker state (small scalars only; the big preview HTML is cached). ---
    public bool $pickerOpen = false;

    public ?string $previewUrl = null;

    /** sha1(url) of the currently-cached preview; the srcdoc + verify read the cache by this. */
    public ?string $previewToken = null;

    public ?string $previewFinalUrl = null;

    /** Where the current preview came from: 'snapshot' (scanned page) or 'live' (manual URL). */
    public ?string $previewSource = null;

    public ?string $previewError = null;

    public ?string $pickedSelector = null;

    public string $pickedPosition = WidgetAppearance::POSITION_AFTER;

    /** @var array<string,mixed>|null */
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

    /** Resolve the CURRENT store (the Filament tenant) and hydrate its appearance — so appearance
        edits apply to the store you are in, not the account's first site. Falls back to a ?site
        deep-link / the first site only when no tenant is bound. */
    public function mount(): void
    {
        $tenant = Filament::getTenant();

        $resolved = $tenant instanceof Site
            ? $tenant
            : (($site = request()->query('site')) !== null
                ? Site::query()->find($site)
                : Site::query()->orderBy('id')->first());

        if ($resolved === null) {
            $this->form->fill(WidgetAppearance::defaults());

            return;
        }

        $this->siteId = (int) $resolved->getKey();
        $this->hasSite = true;

        $rule = ButtonVisibility::resolve($resolved->button_rules);
        $this->form->fill(WidgetAppearance::resolve($resolved->widget_appearance, $resolved->product_category) + [
            self::FIELD_RULE_MODE => $rule->mode,
            self::FIELD_RULE_VALUES => $rule->values,
        ]);

        // Deep-link from the scan-review flow (?pick=1): open the picker straight onto
        // the just-scanned product. Guarded so a bad param never 500s the page load.
        if (filter_var(request()->query('pick'), FILTER_VALIDATE_BOOLEAN)) {
            $this->openPicker();
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('appearance.button.title'))
                    ->description(__('appearance.button.sub'))
                    ->columns(2)
                    ->schema([
                        TextInput::make(WidgetAppearance::KEY_LABEL)
                            ->label(__('appearance.button.label'))
                            ->required()
                            ->maxLength(WidgetAppearance::LABEL_MAX)
                            ->columnSpanFull(),
                        ColorPicker::make(WidgetAppearance::KEY_BUTTON_BG)
                            ->label(__('appearance.button.bg'))
                            ->required(),
                        ColorPicker::make(WidgetAppearance::KEY_BUTTON_TEXT)
                            ->label(__('appearance.button.text'))
                            ->required(),
                        // Placement is chosen with the visual picker (rendered in the page view);
                        // these hidden fields carry it into save() via the form state.
                        Hidden::make(WidgetAppearance::KEY_PLACEMENT),
                        Hidden::make(WidgetAppearance::KEY_CUSTOM_ANCHOR),
                        Hidden::make(WidgetAppearance::KEY_CUSTOM_POSITION),
                    ]),
                Section::make(__('appearance.visibility.title'))
                    ->description(__('appearance.visibility.sub'))
                    ->schema([
                        Select::make(self::FIELD_RULE_MODE)
                            ->label(__('appearance.visibility.mode'))
                            ->options(self::visibilityModeOptions())
                            ->default(ButtonVisibility::MODE_ALL)
                            ->live()
                            ->required(),
                        // The tag / product-type / collection values to match. Hidden for "all".
                        TagsInput::make(self::FIELD_RULE_VALUES)
                            ->label(__('appearance.visibility.values'))
                            ->helperText(__('appearance.visibility.values_help'))
                            ->placeholder(__('appearance.visibility.values_placeholder'))
                            ->visible(fn (Get $get): bool => $get(self::FIELD_RULE_MODE) !== ButtonVisibility::MODE_ALL)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('appearance.popup.title'))
                    ->description(__('appearance.popup.sub'))
                    ->columns(2)
                    ->schema([
                        Select::make(WidgetAppearance::KEY_POPUP_THEME)
                            ->label(__('appearance.popup.theme'))
                            ->options(self::themeOptions())
                            ->required(),
                        ColorPicker::make(WidgetAppearance::KEY_POPUP_ACCENT)
                            ->label(__('appearance.popup.accent'))
                            ->required(),
                        Toggle::make(WidgetAppearance::KEY_ASK_HEIGHT)
                            ->label(__('appearance.popup.ask_height'))
                            ->helperText(__('appearance.popup.ask_height_help'))
                            ->columnSpanFull(),
                        Toggle::make(WidgetAppearance::KEY_ASK_CONSENT)
                            ->label(__('appearance.popup.ask_consent'))
                            ->helperText(__('appearance.popup.ask_consent_help'))
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    // === Visual placement picker actions ===

    /**
     * Open the full-screen picker. The PRIMARY path renders the merchant's most-recently
     * scanned product straight from the stored snapshot (no live fetch) — so "scan a
     * product → see the visual preview" just works. Fully guarded: any failure opens the
     * picker with a soft message + the manual-URL fallback, never a 500.
     */
    public function openPicker(): void
    {
        $this->previewError = null;
        $this->pickVerdict = null;
        $this->pickedSelector = null;

        try {
            $product = $this->latestScannedProduct();

            if ($product !== null) {
                $this->previewUrl = $product->source_url;
                $this->loadProductPreview($product);
            } elseif (blank($this->previewUrl)) {
                $this->previewUrl = $this->site()?->domain ?: null;
            }
        } catch (\Throwable $e) {
            Log::error('widget picker openPicker failed', ['site_id' => $this->siteId, 'error' => $e->getMessage()]);
            $this->previewToken = null;
            $this->previewError = __('appearance.visual.errors.load_failed');
        }

        $this->pickerOpen = true;
    }

    public function closePicker(): void
    {
        $this->pickerOpen = false;
    }

    /**
     * Fetch + sanitize a preview of the URL (via the SSRF-guarded scan fetcher), cache the result,
     * and expose it to the sandboxed iframe. Rate-limited + fail-soft: any fetch failure surfaces a
     * merchant message, never a 500.
     */
    public function loadPreview(): void
    {
        $this->previewError = null;
        $this->previewToken = null;
        $this->pickVerdict = null;
        $this->pickedSelector = null;

        $url = trim((string) $this->previewUrl);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->previewError = __('appearance.visual.errors.bad_url');

            return;
        }

        $limiterKey = 'widget-preview:'.(int) $this->siteId;

        if (RateLimiter::tooManyAttempts($limiterKey, self::PREVIEW_RATE_MAX)) {
            $this->previewError = __('appearance.visual.errors.rate_limited');

            return;
        }

        RateLimiter::hit($limiterKey, self::PREVIEW_RATE_DECAY);

        try {
            $preview = app(PreviewFetcher::class)->previewFor($url, $this->pickerScript());
        } catch (FetchException $e) {
            $this->previewError = $e->merchantMessage();

            return;
        } catch (\Throwable $e) {
            Log::warning('widget picker live preview failed', ['site_id' => $this->siteId, 'error' => $e->getMessage()]);
            $this->previewError = __('appearance.visual.errors.load_failed');

            return;
        }

        $this->cachePreview($preview, $url);
    }

    /** Load the picker preview from a scanned product's stored snapshot (no network). */
    private function loadProductPreview(Product $product): void
    {
        $html = app(PreviewSnapshotStore::class)->get($product);

        if ($html === null) {
            // No snapshot yet (older scan, or storage unavailable) — leave the stage empty;
            // the merchant can still load the URL live via the fallback.
            $this->previewToken = null;
            $this->previewSource = null;

            return;
        }

        $preview = app(PreviewFetcher::class)->previewFromHtml($html, (string) $product->source_url, $this->pickerScript());
        $this->cachePreview($preview, (string) $product->source_url, 'snapshot');
    }

    /** Cache the sanitized preview + expose it to the iframe (shared by snapshot + live paths). */
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

    /** Verify a picked selector resolves against the cached preview DOM (exactly one = good). */
    public function verifyPick(string $selector, string $position): void
    {
        $entry = $this->cachedPreview();

        if ($entry === null) {
            $this->pickVerdict = ['ok' => false, 'count' => 0, 'reason' => 'expired'];

            return;
        }

        $position = in_array($position, WidgetAppearance::POSITIONS, true)
            ? $position
            : WidgetAppearance::POSITION_AFTER;

        try {
            $count = ScanDom::fromHtml((string) $entry['raw'], (string) ($entry['final_url'] ?? ''))->count($selector);
        } catch (\Throwable $e) {
            Log::warning('widget picker verifyPick failed', ['site_id' => $this->siteId, 'error' => $e->getMessage()]);
            $this->pickVerdict = ['ok' => false, 'count' => 0, 'reason' => 'none'];

            return;
        }

        $this->pickedSelector = $selector;
        $this->pickedPosition = $position;
        $this->pickVerdict = [
            'ok' => $count === 1,
            'count' => $count,
            'reason' => $count === 1 ? 'unique' : ($count === 0 ? 'none' : 'multiple'),
        ];
    }

    /** Commit the picked anchor into the form state (custom placement). Save persists it. */
    public function applyPick(): void
    {
        if (blank($this->pickedSelector) || (int) ($this->pickVerdict['count'] ?? 0) < 1) {
            $this->previewError = __('appearance.visual.errors.no_pick');

            return;
        }

        $this->data[WidgetAppearance::KEY_PLACEMENT] = WidgetAppearance::PLACEMENT_CUSTOM;
        $this->data[WidgetAppearance::KEY_CUSTOM_ANCHOR] = $this->pickedSelector;
        $this->data[WidgetAppearance::KEY_CUSTOM_POSITION] = $this->pickedPosition;

        $this->pickerOpen = false;
        Notification::make()->success()->title(__('appearance.visual.applied'))->send();
    }

    /** The "floating corner" alternative inside the picker — a legacy fixed placement, no anchor. */
    public function useFloatingCorner(string $corner): void
    {
        if (! in_array($corner, [WidgetAppearance::PLACEMENT_FIXED_BR, WidgetAppearance::PLACEMENT_FIXED_BL], true)) {
            return;
        }

        $this->data[WidgetAppearance::KEY_PLACEMENT] = $corner;
        $this->data[WidgetAppearance::KEY_CUSTOM_ANCHOR] = '';

        $this->pickerOpen = false;
        Notification::make()->success()->title(__('appearance.visual.applied'))->send();
    }

    /** The sanitized preview HTML for the iframe srcdoc — read from cache, never a Livewire prop. */
    public function previewSrcdoc(): string
    {
        $entry = $this->cachedPreview();

        return $entry !== null ? (string) ($entry['sanitized'] ?? '') : '';
    }

    /** A human summary of the current placement, for the settings section. */
    public function placementSummary(): string
    {
        $placement = (string) ($this->data[WidgetAppearance::KEY_PLACEMENT] ?? WidgetAppearance::PLACEMENT_AFTER_ATC);

        if ($placement === WidgetAppearance::PLACEMENT_CUSTOM) {
            return __('appearance.visual.summary_custom', [
                'position' => __('appearance.visual.position.'.($this->data[WidgetAppearance::KEY_CUSTOM_POSITION] ?? WidgetAppearance::POSITION_AFTER)),
                'anchor' => (string) ($this->data[WidgetAppearance::KEY_CUSTOM_ANCHOR] ?? ''),
            ]);
        }

        return __('appearance.placement.'.$placement);
    }

    /**
     * Validate-then-persist via the service. A typed InvalidSiteSettingsException (bad appearance
     * value) or any other error surfaces a save-failed notice — never a 500, never a partial save.
     */
    public function save(): void
    {
        $site = $this->site();

        if ($site === null) {
            return;
        }

        try {
            $state = $this->form->getState();

            app(SiteSettingsService::class)->update($site, [
                // WidgetAppearance::sanitize keeps only the appearance keys, so the two rule
                // fields riding in the same state are ignored here and captured below instead.
                SiteSettingsService::KEY_WIDGET_APPEARANCE => $state,
                SiteSettingsService::KEY_BUTTON_RULES => [
                    ButtonVisibility::KEY_MODE => $state[self::FIELD_RULE_MODE] ?? ButtonVisibility::MODE_ALL,
                    ButtonVisibility::KEY_VALUES => $state[self::FIELD_RULE_VALUES] ?? [],
                ],
            ]);

            Notification::make()->success()->title(__(self::SAVED))->send();
        } catch (InvalidSiteSettingsException|\Throwable) {
            Notification::make()->danger()->title(__(self::SAVE_FAILED))->send();
        }
    }

    /** The button-visibility modes as value => localised label. */
    private static function visibilityModeOptions(): array
    {
        $options = [];

        foreach (ButtonVisibility::MODES as $mode) {
            $options[$mode] = __('appearance.visibility.mode_'.$mode);
        }

        return $options;
    }

    /** The bound site (account-scoped), or null. */
    public function site(): ?Site
    {
        return $this->siteId !== null
            ? Site::query()->find($this->siteId)
            : null;
    }

    /** The cached preview entry for the current token (account/site-scoped by the key), or null. */
    private function cachedPreview(): ?array
    {
        if ($this->previewToken === null) {
            return null;
        }

        $entry = Cache::get($this->previewCacheKey($this->previewToken));

        return is_array($entry) ? $entry : null;
    }

    /** Cache key is namespaced by the merchant's OWN site id, so no cross-tenant read is possible. */
    private function previewCacheKey(string $token): string
    {
        return 'widget_preview:'.(int) $this->siteId.':'.$token;
    }

    /**
     * The merchant's most-recently scanned product for this site (confirmed preferred,
     * else a draft) — the page the picker previews. Account-scoped by BelongsToAccount.
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

    /** Popup theme value => localised label. */
    private static function themeOptions(): array
    {
        $options = [];

        foreach (WidgetAppearance::THEMES as $theme) {
            $options[$theme] = __('appearance.theme.'.$theme);
        }

        return $options;
    }
}
