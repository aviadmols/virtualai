<?php

namespace App\Filament\Merchant\Pages\Tenancy;

use App\Domain\Sites\StoreCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\EditTenantProfile;

/**
 * EditSiteProfile — the Filament tenant-profile page: edit the ACTIVE shop's core settings
 * ("Shop settings" in the switcher). Filament scopes it to the current tenant Site; the account
 * stays bound (BindMerchantAccount) so the save is account-safe.
 */
class EditSiteProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('sites.profile.label');
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
}
