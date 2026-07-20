<?php

namespace App\Filament\Merchant\Resources\SiteResource\Pages;

use App\Domain\Scan\ScanProductJob;
use App\Filament\Merchant\Concerns\RendersShopHub;
use App\Filament\Merchant\Resources\SiteResource;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * The per-shop OVERVIEW hub as a record-bound page (the {record} resolves through the
 * resource's account-scoped query, so a merchant only ever opens their own shop). All
 * hub rendering (KPI band, quick-links, embed code + key rotation, products, activity)
 * lives in RendersShopHub — shared 1:1 with the tenant-bound Overview widget. This page
 * adds only the "Scan a product" header action (custom-site fallback ingestion).
 *
 * Reachable by deep-link even though the Sites nav is hidden (single-shop model); the
 * Overview widget renders the identical hub for the panel home.
 */
class ViewSite extends ViewRecord
{
    use RendersShopHub;

    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    protected static string $view = 'filament.merchant.resources.site.view';

    // Max length of a pasted product URL (guards the scan input).
    private const SCAN_URL_MAX = 2048;

    /** The hub renders the bound record (account-scoped by the resource query). */
    protected function hubSite(): Site
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return $site;
    }

    /**
     * "Scan a product" — the custom-site fallback ingestion (a Shopify shop syncs its
     * catalog instead). Paste a product-page URL → dispatch the (queued) ScanProductJob
     * → the scanned product lands DRAFT in the products list, linking to the review
     * form. account_id is passed explicitly to the job (never inferred).
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
}
