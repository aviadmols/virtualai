<?php

namespace App\Filament\Platform\Resources\AccountResource\Pages;

use App\Filament\Platform\Resources\AccountResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * P2 — Edit an account's profile fields (name, company, billing email, locale). Status
 * is NOT editable here — it stays on the audited suspend/restore actions (they write
 * activity events). Balance/reserved are never form fields (money safety). No delete:
 * accounts are suspended, never destroyed (data + ledger are kept).
 */
class EditAccount extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = AccountResource::class;

    private const SAVED_TITLE = 'platform.accounts.edit.saved';

    /** Back to the roster after a successful save. */
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
