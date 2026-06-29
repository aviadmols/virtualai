<?php

namespace App\Filament\Platform\Resources\AccountResource\Pages;

use App\Domain\Platform\PlatformAccountProvisioner;
use App\Filament\Platform\Resources\AccountResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * P2 — Provision a new account + its owner login user. Creation goes through the audited
 * PlatformAccountProvisioner (super-admin guarded, account + owner in one transaction);
 * the opening $5 grant is written by AccountObserver, never here. The owner-login fields
 * live in the form's create-only section.
 */
class CreateAccount extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = AccountResource::class;

    private const SAVED_TITLE = 'platform.accounts.create.saved';

    /** Provision account + owner atomically; return the account as the page record. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(PlatformAccountProvisioner::class)->provision($data);
    }

    /** Back to the roster after a successful create. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** Localised success toast (replaces Filament's default English one). */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__(self::SAVED_TITLE));
    }
}
