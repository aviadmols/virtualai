<?php

namespace App\Filament\Merchant\Resources\BannerResource\Pages;

use App\Filament\Merchant\Resources\BannerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Banners index — the merchant's banner list. "New banner" opens the create form (name only),
 * which drops the merchant straight into the editor to generate and place the banner.
 */
class ListBanners extends ListRecords
{
    protected static string $resource = BannerResource::class;

    public function getTitle(): string
    {
        return __('banners.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('banners.new')),
        ];
    }
}
