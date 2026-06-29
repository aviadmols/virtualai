<?php

namespace App\Filament\Platform\Resources\SiteResource\Pages;

use App\Domain\Platform\PlatformSiteWriter;
use App\Filament\Platform\Resources\SiteResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * P3 — Create a site for a chosen account (cross-account, super-admin). The picked
 * account_id is pulled out and the rest flows through the audited PlatformSiteWriter,
 * which binds that account via Tenant::run so the BelongsToAccount creating-hook stamps
 * account_id and generates the site_key / widget_secret. account_id is not a Site
 * fillable, so it is removed from the form data before the write.
 */
class CreateSite extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    private const SAVED_TITLE = 'platform.sites.saved';

    /** Route the create through the audited cross-account write seam. */
    protected function handleRecordCreation(array $data): Model
    {
        $accountId = (int) $data['account_id'];
        unset($data['account_id']);

        return app(PlatformSiteWriter::class)->create($accountId, $data);
    }

    /** Back to the list after a successful create. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** Localised success toast. */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__(self::SAVED_TITLE));
    }
}
