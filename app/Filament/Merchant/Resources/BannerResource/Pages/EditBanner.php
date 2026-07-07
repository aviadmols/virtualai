<?php

namespace App\Filament\Merchant\Resources\BannerResource\Pages;

use App\Domain\Banners\BannerContent;
use App\Domain\Banners\BannerGenerationRequest;
use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Domain\Banners\StartBannerGeneration;
use App\Filament\Merchant\Pages\BannerPlacements;
use App\Filament\Merchant\Resources\BannerResource;
use App\Models\Banner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The banner editor. Generate a banner image from a brief (+ optional reference) via the
 * generation money-path, pick a candidate, set the click target + optional text overlay, and
 * move the banner through its lifecycle. Every write routes through the single validated writer
 * (BannerService); a bad value / illegal move is a typed soft error, never a 500.
 *
 * The generate action + reference upload keep the OpenRouter key server-side; the reference
 * bytes are read once, handed to StartBannerGeneration, and the temp upload is deleted.
 */
class EditBanner extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = BannerResource::class;

    // The private disk the reference upload lands on before we read + delete it.
    private const REF_DISK = 'local';
    private const REF_DIR = 'banner-refs';

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->generateAction(),
            $this->placementsAction(),
            $this->activateAction(),
            $this->pauseAction(),
            $this->archiveAction(),
            DeleteAction::make(),
        ];
    }

    /** Open the visual placement picker (a dedicated page) for this banner. */
    private function placementsAction(): Action
    {
        return Action::make('placements')
            ->label(__('banners.placements.action'))
            ->icon('heroicon-o-map-pin')
            ->color('gray')
            ->url(fn (): string => BannerPlacements::getUrl(['banner' => $this->getRecord()->getKey()]));
    }

    // --- Content save: route through the single validated writer ---

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Banner $record */
        try {
            $service = app(BannerService::class);

            $service->updateContent($record, [
                BannerContent::KEY_NAME => $data['name'] ?? $record->name,
                BannerContent::KEY_COMPOSITION => $data['composition'] ?? $record->composition,
                BannerContent::KEY_TARGET_URL => $data['target_url'] ?? null,
                BannerContent::KEY_ALT_TEXT => $data['alt_text'] ?? null,
                BannerContent::KEY_OVERLAY => $data['overlay'] ?? [],
            ]);

            $assetId = $data['selected_asset_id'] ?? null;

            if (! empty($assetId) && (int) $assetId !== (int) $record->selected_asset_id) {
                // Banner-scoped fetch (defense in depth on top of the account global scope +
                // selectAsset's own banner_id guard): a candidate can only be this banner's.
                $asset = $record->assets()->find((int) $assetId);
                if ($asset !== null) {
                    $service->selectAsset($record, $asset);
                }
            }

            return $record->refresh();
        } catch (InvalidBannerException $e) {
            Notification::make()->danger()->title(__('banners.errors.'.$e->reason))->send();
            $this->halt();
        }
    }

    // --- Generate a candidate (the AI money-path entry) ---

    private function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__('banners.generate.action'))
            ->icon('heroicon-o-sparkles')
            ->modalHeading(__('banners.generate.heading'))
            ->modalSubmitActionLabel(__('banners.generate.submit'))
            ->form([
                Textarea::make('brief')
                    ->label(__('banners.generate.brief'))
                    ->helperText(__('banners.generate.brief_help'))
                    ->required()
                    ->rows(4),
                FileUpload::make('reference')
                    ->label(__('banners.generate.reference'))
                    ->helperText(__('banners.generate.reference_help'))
                    ->image()
                    ->disk(self::REF_DISK)
                    ->directory(self::REF_DIR)
                    ->visibility('private'),
            ])
            ->action(fn (array $data) => $this->generate($data));
    }

    private function generate(array $data): void
    {
        [$referenceBytes, $referenceMime] = $this->readReference($data['reference'] ?? null);

        try {
            app(StartBannerGeneration::class)->handle(new BannerGenerationRequest(
                banner: $this->getRecord(),
                brief: (string) ($data['brief'] ?? ''),
                clientRequestId: (string) Str::uuid(),
                referenceBytes: $referenceBytes,
                referenceMime: $referenceMime,
            ));

            Notification::make()->success()->title(__('banners.generate.queued'))->send();
        } catch (\Throwable $e) {
            // Log the real cause server-side; the merchant sees a single friendly notice.
            Log::warning('banner generation failed to start', [
                'banner_id' => $this->getRecord()->getKey(),
                'error' => $e->getMessage(),
            ]);
            Notification::make()->danger()->title(__('banners.generate.failed'))->send();
        }
    }

    /**
     * Read the optional reference upload's bytes + mime, then delete the temp file. Fail-soft:
     * any read problem drops the reference (the brief alone still generates), never a 500.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function readReference(mixed $upload): array
    {
        $path = is_array($upload) ? (reset($upload) ?: null) : $upload;

        if (! is_string($path) || $path === '') {
            return [null, null];
        }

        try {
            $disk = Storage::disk(self::REF_DISK);
            if (! $disk->exists($path)) {
                return [null, null];
            }
            $bytes = $disk->get($path);
            $mime = $disk->mimeType($path) ?: 'image/png';
            $disk->delete($path);

            return [$bytes, $mime];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    // --- Lifecycle actions (guarded by BannerService) ---

    private function activateAction(): Action
    {
        return Action::make('activate')
            ->label(__('banners.action.activate'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->visible(fn (): bool => in_array($this->getRecord()->status, [Banner::STATUS_DRAFT, Banner::STATUS_PAUSED], true))
            ->action(fn () => $this->changeStatus(Banner::STATUS_ACTIVE));
    }

    private function pauseAction(): Action
    {
        return Action::make('pause')
            ->label(__('banners.action.pause'))
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->visible(fn (): bool => $this->getRecord()->status === Banner::STATUS_ACTIVE)
            ->action(fn () => $this->changeStatus(Banner::STATUS_PAUSED));
    }

    private function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(__('banners.action.archive'))
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->getRecord()->status !== Banner::STATUS_ARCHIVED)
            ->action(fn () => $this->changeStatus(Banner::STATUS_ARCHIVED));
    }

    private function changeStatus(string $status): void
    {
        try {
            app(BannerService::class)->setStatus($this->getRecord(), $status);
            $this->refreshFormData(['status']);
            Notification::make()->success()->title(__('banners.saved'))->send();
        } catch (InvalidBannerException $e) {
            Notification::make()->danger()->title(__('banners.errors.'.$e->reason))->send();
        }
    }
}
