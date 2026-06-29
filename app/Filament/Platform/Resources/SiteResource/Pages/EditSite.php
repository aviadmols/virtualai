<?php

namespace App\Filament\Platform\Resources\SiteResource\Pages;

use App\Domain\Platform\PlatformSiteWriter;
use App\Filament\Platform\Resources\SiteResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * P3 — Edit a site (cross-account, super-admin). The record is resolved through the
 * audited PlatformSiteQuery seam (resource getEloquentQuery); the save runs through the
 * audited PlatformSiteWriter, which binds the site's own account via Tenant::run so the
 * global scope holds. The owning account is fixed (the form disables account_id on edit).
 */
class EditSite extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = SiteResource::class;

    private const SAVED_TITLE = 'platform.sites.updated';

    /** Route the update through the audited cross-account write seam. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(PlatformSiteWriter::class)->update($record, $data);
    }

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
