<?php

namespace App\Filament\Merchant\Resources\SiteResource\Pages;

use App\Filament\Merchant\Resources\SiteResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * M2 — Edit a site's basics (name, domain, allowed origins). Tenant-safe by the merchant
 * binding: BindMerchantAccount scopes the record to the owner's account, so a merchant
 * can only ever resolve and save its OWN site (the global scope blocks the rest). The
 * site_key / widget_secret are never shown or edited here; deeper privacy/retention
 * settings live on the PrivacySettings page.
 */
class EditSite extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    private const SAVED_TITLE = 'sites.updated';

    /** Back to the list after a successful save. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** Localised success toast. */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__(self::SAVED_TITLE));
    }
}
