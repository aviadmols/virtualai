<?php

namespace App\Filament\Merchant\Resources\SiteResource\Pages;

use App\Domain\Activity\EndUserActivityItem;
use App\Domain\Activity\SiteActivityTimeline;
use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\SiteHubMetrics;
use App\Domain\Reporting\SiteHubMetricsBuilder;
use App\Domain\Scan\ScanProductJob;
use App\Domain\Sites\SiteKeyRegenerator;
use App\Filament\Merchant\Pages\Gallery;
use App\Filament\Merchant\Pages\PrivacySettings;
use App\Filament\Merchant\Pages\ReviewProduct;
use App\Filament\Merchant\Pages\TryOnHistory;
use App\Filament\Merchant\Pages\WidgetAppearanceSettings;
use App\Filament\Merchant\Resources\EndUserResource;
use App\Filament\Merchant\Resources\SiteResource;
use App\Models\Product;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * WS1 — the per-shop OVERVIEW hub. A record-bound page (the {record} resolves
 * through the resource's account-scoped query, so a merchant only ever opens their
 * own shop). Ties the shop's tools together: a KPI band (confirmed products,
 * try-ons over the window, leads, spendable credit), quick-link cards to the shop's
 * management surfaces (button placement, try-on history, registered users, gallery,
 * privacy), the embed-code block (the PUBLIC site_key only) with a destructive
 * "regenerate key" action wired to SiteKeyRegenerator, the shop's scanned products
 * — each linking to the A4 scan-review form (M3) — and a recent-activity strip.
 *
 * The page renders typed data only: the KPIs come from a SiteHubMetrics snapshot
 * (account+site-scoped, aggregated in PHP — NEVER in Blade), products through the
 * shop's account-scoped relation, and the activity strip through SiteActivityTimeline.
 * The regenerate action returns the new public key and the widget_secret is NEVER
 * touched or shown.
 */
class ViewSite extends ViewRecord
{
    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    protected static string $view = 'filament.merchant.resources.site.view';

    // The widget loader path is owned by widget-embed (Phase 9 build). We render
    // the snippet against this convention; if the path changes, escalate — don't
    // guess a second one.
    private const WIDGET_SCRIPT_PATH = '/widget/v1/widget.js';

    // i18n keys.
    private const NOTIFY_REGENERATED = 'embed.regenerated';
    private const NOTIFY_ERROR = 'embed.errors.regenerate';

    // Max length of a pasted product URL (guards the scan input).
    private const SCAN_URL_MAX = 2048;

    // KPI-band label keys (sites.hub.kpi.*), one per SiteHubMetrics field the band shows.
    private const KPI_LABEL_PRODUCTS = 'sites.hub.kpi.products';
    private const KPI_LABEL_GENERATIONS = 'sites.hub.kpi.generations';
    private const KPI_LABEL_LEADS = 'sites.hub.kpi.leads';
    private const KPI_LABEL_BALANCE = 'sites.hub.kpi.balance';

    // KPI accent tones (StatusBadge vocabulary → the KPI card edge).
    private const TONE_NEUTRAL = 'neutral';
    private const TONE_INFO = 'info';
    private const TONE_SUCCESS = 'success';
    private const TONE_WARN = 'warn';

    // How many recent activity rows the hub strip shows.
    private const ACTIVITY_LIMIT = 6;

    /** The two-step destructive confirm + the in-flight/error states (Alpine-free,
        Livewire-driven so the server owns the rotation). */
    public bool $confirmingRegenerate = false;

    public bool $regenerateError = false;

    /**
     * Site-hub header actions. "Scan a product" is the entry point the merchant uses
     * after installing the header snippet: paste a product-page URL → dispatch the
     * (queued) ScanProductJob → the scanned product lands DRAFT in the products list
     * below, where it links to the A4 review/confirm form. account_id is passed
     * explicitly to the job (never inferred), per the tenancy contract.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label(__('sites.scan.label'))
                ->icon('heroicon-o-magnifying-glass')
                ->modalHeading(__('sites.scan.heading'))
                ->modalDescription(__('sites.scan.sub'))
                ->modalSubmitActionLabel(__('sites.scan.submit'))
                ->form([
                    TextInput::make('url')
                        ->label(__('sites.scan.url'))
                        ->placeholder(__('sites.scan.url_placeholder'))
                        ->url()
                        ->required()
                        ->maxLength(self::SCAN_URL_MAX),
                ])
                ->action(function (array $data): void {
                    $site = $this->getRecord();

                    ScanProductJob::dispatch((int) $site->account_id, (int) $site->getKey(), $data['url']);

                    Notification::make()
                        ->success()
                        ->title(__('sites.scan.queued'))
                        ->body(__('sites.scan.queued_body'))
                        ->send();
                }),
        ];
    }

    /** The full widget.js src for the embed snippet (public site_key carried in the
        data attribute by the component, never here). */
    public function scriptSrc(): string
    {
        return rtrim((string) config('app.url'), '/').self::WIDGET_SCRIPT_PATH;
    }

    /** The install-guide link (kept a config-free convention for now). */
    public function installUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/docs/install';
    }

    /** The site's scanned products (account-scoped via the relation), newest first. */
    public function products(): Collection
    {
        return Product::query()
            ->where('site_id', $this->getRecord()->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    /** The deep link to the A4 review form for one product of this site. */
    public function reviewUrl(Product $product): string
    {
        return ReviewProduct::getUrl([
            'site' => $this->getRecord()->getKey(),
            'product' => $product->getKey(),
        ]);
    }

    /** The deep link to this site's M8 gallery. */
    public function galleryUrl(): string
    {
        return Gallery::getUrl(['site' => $this->getRecord()->getKey()]);
    }

    /** The deep link to this site's M9 privacy & retention settings. */
    public function privacyUrl(): string
    {
        return PrivacySettings::getUrl(['site' => $this->getRecord()->getKey()]);
    }

    /**
     * The deep link to the button-placement visual picker for THIS shop, opened
     * straight onto the picker (?pick=1) — the quickest path to place the button.
     */
    public function placementUrl(): string
    {
        return WidgetAppearanceSettings::getUrl(['site' => $this->getRecord()->getKey(), 'pick' => 1]);
    }

    /** The deep link to this shop's try-on history (WS2). */
    public function historyUrl(): string
    {
        return TryOnHistory::getUrl();
    }

    /** The deep link to this shop's registered users / leads list (M5). */
    public function usersUrl(): string
    {
        return EndUserResource::getUrl('index');
    }

    /**
     * The KPI band for this shop, as a flat render-ready array. Each entry carries
     * its i18n label key, a PRE-FORMATTED value string, and a tone. The value is
     * aggregated + formatted in PHP (SiteHubMetrics → here); the view never computes
     * a number.
     *
     * @return array<int,array{label:string,value:string,tone:string}>
     */
    public function kpis(): array
    {
        $metrics = $this->metrics();

        return [
            [
                'label' => self::KPI_LABEL_PRODUCTS,
                'value' => $this->int($metrics->productsConfirmed),
                'tone' => self::TONE_NEUTRAL,
            ],
            [
                'label' => self::KPI_LABEL_GENERATIONS,
                'value' => $this->int($metrics->generationsInWindow),
                'tone' => self::TONE_INFO,
            ],
            [
                'label' => self::KPI_LABEL_LEADS,
                'value' => $this->int($metrics->leadsTotal),
                'tone' => self::TONE_INFO,
            ],
            [
                'label' => self::KPI_LABEL_BALANCE,
                'value' => $this->usd($metrics->spendableMicroUsd),
                'tone' => $metrics->isLowBalance ? self::TONE_WARN : self::TONE_SUCCESS,
            ],
        ];
    }

    /** The shop's recent activity (newest first) as immutable timeline DTOs. */
    public function activity(): Collection
    {
        return app(SiteActivityTimeline::class)->forSite($this->getRecord(), self::ACTIVITY_LIMIT);
    }

    /** The account+site-scoped hub snapshot for this shop. */
    private function metrics(): SiteHubMetrics
    {
        return app(SiteHubMetricsBuilder::class)->build($this->getRecord());
    }

    /** Locale-aware integer formatting (display only — no aggregation here). */
    private function int(int $value): string
    {
        return number_format($value);
    }

    /** Integer micro-USD of selling value → a $X.XX display string. */
    private function usd(int $microUsd): string
    {
        return '$'.number_format(CreditMath::microToUsd($microUsd), 2);
    }

    /** Open the destructive two-step confirm. */
    public function askRegenerate(): void
    {
        $this->regenerateError = false;
        $this->confirmingRegenerate = true;
    }

    /** Dismiss the confirm without rotating. */
    public function cancelRegenerate(): void
    {
        $this->confirmingRegenerate = false;
    }

    /**
     * Rotate the PUBLIC site_key via SiteKeyRegenerator. The action records the
     * activity event and returns the new key; widget_secret is untouched. The
     * record is refreshed so the embed snippet shows the new key immediately.
     */
    public function regenerate(): void
    {
        $this->confirmingRegenerate = false;

        try {
            app(SiteKeyRegenerator::class)->regenerate($this->getRecord());
            $this->record = $this->getRecord()->fresh();

            Notification::make()
                ->title(__(self::NOTIFY_REGENERATED))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->regenerateError = true;

            Notification::make()
                ->title(__(self::NOTIFY_ERROR))
                ->danger()
                ->send();
        }
    }

    protected function getViewData(): array
    {
        return [
            'site' => $this->getRecord(),
            'products' => $this->products(),
            'kpis' => $this->kpis(),
            'activity' => $this->activity(),
        ];
    }
}
