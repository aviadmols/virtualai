<?php

namespace App\Filament\Merchant\Resources\SiteResource\Pages;

use App\Domain\Sites\SiteKeyRegenerator;
use App\Filament\Merchant\Pages\Gallery;
use App\Filament\Merchant\Pages\PrivacySettings;
use App\Filament\Merchant\Pages\ReviewProduct;
use App\Filament\Merchant\Resources\SiteResource;
use App\Models\Product;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * M4 / A5 — the site hub. A record-bound page (the {record} resolves through the
 * resource's account-scoped query, so a merchant only ever opens their own site).
 * Surfaces the embed-code block (the PUBLIC site_key only) with a destructive
 * "regenerate key" action wired to SiteKeyRegenerator, plus the site's scanned
 * products — each linking to the A4 scan-review form (M3).
 *
 * The page renders typed data only: products are read through the site's
 * account-scoped relation; the regenerate action returns the new public key and
 * the widget_secret is NEVER touched or shown.
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

    /** The two-step destructive confirm + the in-flight/error states (Alpine-free,
        Livewire-driven so the server owns the rotation). */
    public bool $confirmingRegenerate = false;

    public bool $regenerateError = false;

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
        ];
    }
}
