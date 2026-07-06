<?php

namespace App\Filament\Merchant\Resources\BannerResource\Pages;

use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Filament\Merchant\Resources\BannerResource;
use App\Models\Site;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Create a banner (name only) → a DRAFT, then straight to the editor. The write routes through
 * the single validated writer (BannerService::createDraft) under the bound shop tenant; a bad
 * name is a typed soft error, never a 500. account_id is stamped by BelongsToAccount.
 */
class CreateBanner extends CreateRecord
{
    protected static string $resource = BannerResource::class;

    public function getTitle(): string
    {
        return __('banners.new');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $site = Filament::getTenant();

        if (! $site instanceof Site) {
            $this->halt();
        }

        try {
            return app(BannerService::class)->createDraft($site, (string) ($data['name'] ?? ''));
        } catch (InvalidBannerException $e) {
            Notification::make()->danger()->title(__('banners.errors.'.$e->reason))->send();
            $this->halt();
        }
    }
}
