<?php

namespace App\Filament\Merchant\Pages;

use App\Domain\Sites\InvalidSiteSettingsException;
use App\Domain\Sites\SiteSettingsService;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * M9 / A13 — the per-site privacy & retention settings form. Binds 1:1 to
 * SiteSettingsService::update($site, $patch), which writes ONLY the four whitelisted
 * columns (retention_days, privacy_config, gallery_settings, free_generations_before_signup)
 * and VALIDATES the whole patch before any write. An out-of-range value throws a typed
 * InvalidSiteSettingsException (->field, ->reason) that this page renders as a field
 * error — never a 500, never a partial save.
 *
 * Tenant-safety: the site is resolved through the BelongsToAccount global scope (the
 * panel is account-bound), so a foreign site 404s — no manual where(account_id), no
 * withoutGlobalScopes(). The service forceFills only the exact column set, so a save can
 * never reach site_key / widget_secret / allowed_origins. The page picks the account's
 * first site by default; a ?site={id} deep-link (from the site hub) selects another own site.
 */
class PrivacySettings extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'nav.settings';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.merchant.pages.privacy-settings';

    // The retention-window choices (days). null = until manual delete (the sentinel).
    // The form binds a string token so "until_delete" is selectable; mapped back on save.
    private const RETENTION_UNTIL_DELETE_TOKEN = 'until_delete';

    // The UI sub-keys this form owns inside the two opaque JSON config columns. These
    // are this surface's contract (the backend stores them as opaque blobs); the widget
    // + lead card read them later. Flagged to product-ux-architect for the catalog.
    private const GALLERY_SHOW_KEY = 'show_in_gallery';
    private const PRIVACY_BLUR_KEY = 'blur_source_photo';

    // Maps the service's reason → the field whose error line it renders under.
    private const REASON_FIELD = [
        InvalidSiteSettingsException::REASON_RETENTION_DAYS => 'retentionDays',
        InvalidSiteSettingsException::REASON_FREE_GENERATIONS => 'freeGenerations',
        InvalidSiteSettingsException::REASON_NOT_AN_OBJECT => 'retentionDays',
    ];

    // i18n keys.
    private const TITLE = 'settings.privacy.title';
    private const NAV_LABEL = 'settings.privacy.nav';
    private const NOTIFY_SAVED = 'settings.privacy.saved';
    private const NOTIFY_SAVE_FAILED = 'settings.privacy.errors.save_failed';
    private const ERROR_PREFIX = 'settings.privacy.errors.';

    /** The bound site id (scalar — Livewire-safe; the model re-resolves on demand). */
    public ?int $siteId = null;

    public bool $hasSite = false;

    // --- Form state (public Livewire props) ---
    /** Retention window: "7" | "30" | "90" | "until_delete". */
    public string $retentionDays = '';

    /** Free try-ons before signup: "" (never) | "0" | a positive integer string. */
    public string $freeGenerations = '';

    /** gallery_settings.show_in_gallery toggle. */
    public bool $showInGallery = true;

    /** privacy_config.blur_source_photo toggle. */
    public bool $blurSourcePhoto = true;

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

    /** Resolve the CURRENT store (the Filament tenant) and hydrate the form from its columns —
        so privacy + the free-try-before-signup setting apply to the store you are in, not the
        account's first site. Falls back to a ?site deep-link / first site only with no tenant. */
    public function mount(): void
    {
        $tenant = Filament::getTenant();

        $resolved = $tenant instanceof Site
            ? $tenant
            : (($site = request()->query('site')) !== null
                ? Site::query()->find($site)
                : Site::query()->orderBy('id')->first());

        if ($resolved === null) {
            return;
        }

        $this->siteId = (int) $resolved->getKey();
        $this->hasSite = true;
        $this->hydrateFrom($resolved);
    }

    /** The bound site (account-scoped), or null. */
    public function site(): ?Site
    {
        return $this->siteId !== null
            ? Site::query()->find($this->siteId)
            : null;
    }

    /** The retention-window select options (value => i18n label). */
    public function retentionOptions(): array
    {
        $options = [];

        foreach (Site::RETENTION_DAYS_ALLOWED as $days) {
            $options[(string) $days] = __('settings.privacy.retention.'.$days);
        }

        $options[self::RETENTION_UNTIL_DELETE_TOKEN] = __('settings.privacy.retention.until_delete');

        return $options;
    }

    /**
     * Validate-then-persist via the service. A typed InvalidSiteSettingsException is
     * mapped to a field error (no 500, no partial save); any other throwable shows a
     * generic save-failed notice. On success, a saved notification.
     */
    public function save(): void
    {
        $site = $this->site();

        if ($site === null) {
            return;
        }

        try {
            app(SiteSettingsService::class)->update($site, $this->patch());

            Notification::make()->title(__(self::NOTIFY_SAVED))->success()->send();
        } catch (InvalidSiteSettingsException $e) {
            $field = self::REASON_FIELD[$e->reason] ?? 'retentionDays';
            $this->addError($field, __(self::ERROR_PREFIX.$e->reason));
        } catch (\Throwable) {
            Notification::make()->title(__(self::NOTIFY_SAVE_FAILED))->danger()->send();
        }
    }

    /** Seed the form props from the site's current settings. */
    private function hydrateFrom(Site $site): void
    {
        $this->retentionDays = $site->retention_days === Site::RETENTION_UNTIL_DELETE
            ? self::RETENTION_UNTIL_DELETE_TOKEN
            : (string) $site->retention_days;

        $this->freeGenerations = $site->free_generations_before_signup === null
            ? ''
            : (string) $site->free_generations_before_signup;

        $gallery = $site->gallery_settings ?? [];
        $privacy = $site->privacy_config ?? [];

        $this->showInGallery = (bool) ($gallery[self::GALLERY_SHOW_KEY] ?? true);
        $this->blurSourcePhoto = (bool) ($privacy[self::PRIVACY_BLUR_KEY] ?? true);
    }

    /**
     * Build the validated patch for the service. Retention maps the until-delete token
     * to null; free-generations maps "" to null (never) and otherwise to a (possibly
     * invalid) int — the service is the single validator, so an out-of-range value still
     * throws there rather than being silently coerced here.
     *
     * @return array<string,mixed>
     */
    private function patch(): array
    {
        return [
            SiteSettingsService::KEY_RETENTION_DAYS => $this->retentionValue(),
            SiteSettingsService::KEY_FREE_GENERATIONS => $this->freeGenerationsValue(),
            SiteSettingsService::KEY_GALLERY_SETTINGS => [
                self::GALLERY_SHOW_KEY => $this->showInGallery,
            ],
            SiteSettingsService::KEY_PRIVACY_CONFIG => [
                self::PRIVACY_BLUR_KEY => $this->blurSourcePhoto,
            ],
        ];
    }

    /** Map the retention token to the column value (until-delete → null sentinel). */
    private function retentionValue(): ?int
    {
        if ($this->retentionDays === self::RETENTION_UNTIL_DELETE_TOKEN || $this->retentionDays === '') {
            return Site::RETENTION_UNTIL_DELETE;
        }

        // Cast a numeric token to int; a non-numeric token stays as-is so the service
        // rejects it (the service is the single source of validity).
        return is_numeric($this->retentionDays) ? (int) $this->retentionDays : null;
    }

    /** Map the free-generations input to the column value ("" → null = never). */
    private function freeGenerationsValue(): ?int
    {
        $value = trim($this->freeGenerations);

        if ($value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : -1; // -1 forces the service to reject a bad value.
    }
}
