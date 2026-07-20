<?php

namespace App\Filament\Merchant\Concerns;

use App\Domain\Activity\SiteActivityTimeline;
use App\Domain\Credits\CreditMath;
use App\Domain\Reporting\SiteHubMetrics;
use App\Domain\Reporting\SiteHubMetricsBuilder;
use App\Domain\Sites\SiteKeyRegenerator;
use App\Filament\Merchant\Pages\Gallery;
use App\Filament\Merchant\Pages\PrivacySettings;
use App\Filament\Merchant\Pages\ReviewProduct;
use App\Filament\Merchant\Pages\TryOnHistory;
use App\Filament\Merchant\Pages\WidgetAppearanceSettings;
use App\Filament\Merchant\Resources\EndUserResource;
use App\Models\Product;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * RendersShopHub — the per-shop OVERVIEW hub, shared by the record-bound ViewSite page
 * and the tenant-bound Overview widget so both render ONE hub surface (no drift). The
 * host supplies the shop via hubSite() (ViewSite → getRecord(); the Overview widget →
 * the Filament tenant); everything else lives here: the KPI band, quick-link cards, the
 * embed-code block + PUBLIC-key rotation, the products list, and the activity strip.
 *
 * Values are aggregated in PHP (SiteHubMetrics) — never in Blade. The rotation touches
 * only the public site_key via SiteKeyRegenerator; the widget_secret is never read.
 */
trait RendersShopHub
{
    // === CONSTANTS ===
    // The widget loader path (owned by widget-embed); the snippet is rendered against it.
    private const WIDGET_SCRIPT_PATH = '/widget/v1/widget.js';

    // i18n notification keys for the key rotation.
    private const NOTIFY_REGENERATED = 'embed.regenerated';

    private const NOTIFY_ERROR = 'embed.errors.regenerate';

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

    /** The two-step destructive confirm + error state (Livewire-driven; server owns the rotation). */
    public bool $confirmingRegenerate = false;

    public bool $regenerateError = false;

    /** The shop this hub renders — the record (ViewSite) or the tenant (Overview widget). */
    abstract protected function hubSite(): Site;

    /** The full widget.js src for the embed snippet (public site_key carried by the component). */
    public function scriptSrc(): string
    {
        return rtrim((string) config('app.url'), '/').self::WIDGET_SCRIPT_PATH;
    }

    /** The install-guide link (a config-free convention for now). */
    public function installUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/docs/install';
    }

    /** The shop's products (account-scoped via the relation), newest first. */
    public function products(): Collection
    {
        return Product::query()
            ->where('site_id', $this->hubSite()->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    /** The deep link to the A4 review form for one product of this shop. */
    public function reviewUrl(Product $product): string
    {
        return ReviewProduct::getUrl([
            'site' => $this->hubSite()->getKey(),
            'product' => $product->getKey(),
        ]);
    }

    /** The deep link to this shop's gallery. */
    public function galleryUrl(): string
    {
        return Gallery::getUrl(['site' => $this->hubSite()->getKey()]);
    }

    /** The deep link to this shop's privacy & retention settings. */
    public function privacyUrl(): string
    {
        return PrivacySettings::getUrl(['site' => $this->hubSite()->getKey()]);
    }

    /** The deep link to the button-placement visual picker, opened straight onto the picker. */
    public function placementUrl(): string
    {
        return WidgetAppearanceSettings::getUrl(['site' => $this->hubSite()->getKey(), 'pick' => 1]);
    }

    /** The deep link to this shop's try-on history. */
    public function historyUrl(): string
    {
        return TryOnHistory::getUrl();
    }

    /** The deep link to this shop's registered users / leads list. */
    public function usersUrl(): string
    {
        return EndUserResource::getUrl('index');
    }

    /**
     * The KPI band for this shop, as a flat render-ready array. Each entry carries its
     * i18n label key, a PRE-FORMATTED value string, and a tone (aggregated in PHP).
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
        return app(SiteActivityTimeline::class)->forSite($this->hubSite(), self::ACTIVITY_LIMIT);
    }

    /** The account+site-scoped hub snapshot for this shop. */
    private function metrics(): SiteHubMetrics
    {
        return app(SiteHubMetricsBuilder::class)->build($this->hubSite());
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
     * Rotate the PUBLIC site_key via SiteKeyRegenerator. The action records the activity
     * event; the widget_secret is untouched. The shop model is refreshed in place so the
     * embed snippet shows the new key immediately.
     */
    public function regenerate(): void
    {
        $this->confirmingRegenerate = false;

        try {
            app(SiteKeyRegenerator::class)->regenerate($this->hubSite());
            $this->hubSite()->refresh();

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
}
