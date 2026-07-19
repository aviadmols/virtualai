<?php

namespace App\Filament\Merchant\Pages\Tenancy;

use App\Domain\Sites\StoreCategory;
use App\Models\Site;
use App\Support\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * RegisterSite — the Filament tenant-registration page: add a new shop from the merchant panel
 * (the switcher's "register shop" flow). The new Site is stamped with the merchant's own
 * account_id by binding their account for the create (the account is the security boundary — a
 * merchant can only create shops under their own account).
 */
class RegisterSite extends RegisterTenant
{
    public static function getLabel(): string
    {
        return __('sites.register.label');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label(__('sites.field.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('domain')
                ->label(__('sites.field.domain'))
                ->placeholder(__('sites.field.domain_placeholder'))
                ->url()
                ->maxLength(255),
            Select::make('product_category')
                ->label(__('sites.field.category'))
                ->helperText(__('sites.field.category_help'))
                ->options(StoreCategory::options())
                ->native(false),
            TagsInput::make('allowed_origins')
                ->label(__('sites.field.origins'))
                ->placeholder(__('sites.field.origins_placeholder'))
                ->helperText(__('sites.field.origins_help')),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        $accountId = auth()->user()?->account_id;

        // A user with no account (e.g. a platform super-admin) cannot own a shop. Fail
        // gracefully with a notification instead of a 500 — Halt stops the action cleanly
        // (RegisterTenant::register catches it and rolls the transaction back).
        if ($accountId === null) {
            Notification::make()
                ->danger()
                ->title(__('sites.register.not_owner'))
                ->send();

            throw new Halt();
        }

        return Tenant::run((int) $accountId, static fn (): Site => Site::create($data));
    }
}
