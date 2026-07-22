<?php

namespace App\Filament\Merchant\Resources\BannerResource\Pages;

use App\Domain\Banners\BannerContent;
use App\Domain\Banners\BannerGenerationRequest;
use App\Domain\Banners\BannerRules;
use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Domain\Banners\StartBannerGeneration;
use App\Domain\Media\MediaStorage;
use App\Filament\Merchant\Pages\BannerPlacements;
use App\Filament\Merchant\Resources\BannerResource;
use App\Models\Banner;
use App\Models\Product;
use App\Models\StylePreset;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\View;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

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

    // How many products the @-mention picker lists (same bound as the try-on prompt editor).
    private const PRODUCT_LIMIT = 50;

    // The shared visual style picker (sample cards) rendered inside the Generate modal.
    private const STYLE_PICKER_VIEW = 'filament.merchant.components.style-picker';

    // The Generate modal width — roomy enough for the style cards + the brief side by side.
    private const GENERATE_MODAL_WIDTH = '3xl';

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

    /**
     * The candidates gallery (embedded inline in the form) selected a new artwork: reload the
     * page's record so the header actions — Activate needs artwork — see it immediately.
     */
    #[On('banner-artwork-selected')]
    public function refreshAfterArtwork(): void
    {
        $this->getRecord()->refresh();
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

    /** Hydrate the rules fields from the resolved config so a fresh banner shows sane defaults. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['rules'] = BannerRules::resolve($this->getRecord()->rules);

        return $data;
    }

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

            $service->updateRules($record, $this->rulesFrom($data));

            // Artwork selection is handled live by the BannerCandidatesWidget (below the form),
            // not on save — the form no longer carries selected_asset_id.

            return $record->refresh();
        } catch (InvalidBannerException $e) {
            Notification::make()->danger()->title(__('banners.errors.'.$e->reason))->send();
            $this->halt();
        }
    }

    /**
     * Assemble the display-rules patch from the nested form data, casting the numeric field the
     * validator expects as an int (a Filament numeric input yields a string). The single validator
     * (BannerRules::sanitize) still rejects any out-of-range/unknown value.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function rulesFrom(array $data): array
    {
        $r = is_array($data['rules'] ?? null) ? $data['rules'] : [];

        return [
            BannerRules::KEY_AUDIENCE => $r[BannerRules::KEY_AUDIENCE] ?? BannerRules::AUDIENCE_ANY,
            BannerRules::KEY_PAGES => [
                BannerRules::KEY_PAGE_CONTEXT => $r['pages'][BannerRules::KEY_PAGE_CONTEXT] ?? BannerRules::PAGE_ANY,
                BannerRules::KEY_PAGE_URL_CONTAINS => $r['pages'][BannerRules::KEY_PAGE_URL_CONTAINS] ?? null,
            ],
            BannerRules::KEY_SCHEDULE => [
                BannerRules::KEY_SCHEDULE_STARTS_AT => $r['schedule'][BannerRules::KEY_SCHEDULE_STARTS_AT] ?? null,
                BannerRules::KEY_SCHEDULE_ENDS_AT => $r['schedule'][BannerRules::KEY_SCHEDULE_ENDS_AT] ?? null,
            ],
            BannerRules::KEY_FREQUENCY => [
                BannerRules::KEY_FREQUENCY_MAX => (int) ($r['frequency'][BannerRules::KEY_FREQUENCY_MAX] ?? 0),
            ],
            BannerRules::KEY_LOCALES => array_values((array) ($r[BannerRules::KEY_LOCALES] ?? [])),
        ];
    }

    // --- Generate a candidate (the AI money-path entry) ---

    private function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__('banners.generate.action'))
            ->icon('heroicon-o-sparkles')
            ->modalIcon('heroicon-o-sparkles')
            ->modalHeading(__('banners.generate.heading'))
            ->modalDescription(__('banners.generate.sub'))
            ->modalWidth(self::GENERATE_MODAL_WIDTH)
            ->modalSubmitActionLabel(__('banners.generate.submit'))
            ->form([
                // Optional global STYLE — the approved banner presets as OPEN visual sample cards
                // (not a dropdown), plus a leading "free style" card since a style is optional.
                // Picking a card only swaps the prompt; the brief still guides the content.
                ViewField::make('style_id')
                    ->label(__('banners.generate.style'))
                    ->helperText(__('banners.generate.style_help'))
                    ->view(self::STYLE_PICKER_VIEW)
                    ->viewData(fn (): array => [
                        'styles' => $this->styleCards(),
                        'allowNone' => true,
                        'noneLabel' => __('banners.generate.style_none'),
                        'noneHelp' => __('banners.generate.style_none_help'),
                    ])
                    ->default('')
                    ->visible(fn (): bool => $this->styleOptions() !== [])
                    ->live(),
                Textarea::make('brief')
                    ->label(__('banners.generate.brief'))
                    ->helperText(__('banners.generate.brief_help'))
                    ->placeholder(__('banners.generate.brief_placeholder'))
                    ->required()
                    ->rows(5)
                    // The @-mention picker (below) targets this field to insert @product_{id} tags.
                    ->extraInputAttributes(['data-banner-brief' => 'true']),
                // Tag a product with @ so the banner is generated FROM it (its image + facts). The
                // picker inserts @product_{id} tokens the generation job resolves — GenerateBannerJob.
                View::make('filament.merchant.forms.banner-mention-picker')
                    ->viewData(['products' => $this->productOptions()]),
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
                // '' is the "free style" card (no preset) — only a numeric id selects a style.
                styleId: is_numeric($data['style_id'] ?? null) ? (int) $data['style_id'] : null,
            ));

            Notification::make()->success()->title(__('banners.generate.queued'))->send();
            // Wake the candidates widget so the new pending candidate shows and polling starts.
            $this->dispatch('banner-generation-started');
        } catch (\Throwable $e) {
            // Log the real cause server-side; the merchant sees a single friendly notice.
            Log::warning('banner generation failed to start', [
                'banner_id' => $this->getRecord()->getKey(),
                'error' => $e->getMessage(),
            ]);
            Notification::make()->danger()->title(__('banners.generate.failed'))->send();
        }
    }

    /** Approved banner styles (id => name) — the cheap existence check for the picker. @return array<int,string> */
    private function styleOptions(): array
    {
        return StylePreset::query()
            ->approvedForOperations(StylePreset::SURFACE_OPERATIONS[StylePreset::SURFACE_BANNER])
            ->pluck('name', 'id')->all();
    }

    /**
     * The approved banner styles as visual sample cards: id, name, and short-lived signed URLs
     * for the generated SAMPLE (after) + the uploaded REFERENCE (before) — the Image-Studio
     * style-picker idiom. No operation sub-label: every banner style shares one operation.
     *
     * @return array<int,array<string,mixed>>
     */
    private function styleCards(): array
    {
        $media = app(MediaStorage::class);

        return StylePreset::query()
            ->approvedForOperations(StylePreset::SURFACE_OPERATIONS[StylePreset::SURFACE_BANNER])
            ->get(['id', 'name', 'sample_image_path', 'reference_image_path'])
            ->map(fn (StylePreset $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'operation' => null,
                'after' => $media->signedUrl($p->sample_image_path),
                'before' => $media->signedUrl($p->reference_image_path),
            ])
            ->all();
    }

    /**
     * Active products for THIS banner's shop (id => name) — the @-mention picker's list.
     * Scoped to the banner's own site (which IS the Filament tenant, per BannerResource) on
     * top of the account global scope, so it never lists another shop's products.
     *
     * @return array<int,string>
     */
    private function productOptions(): array
    {
        return Product::query()
            ->where('site_id', $this->getRecord()->site_id)
            ->active()
            ->orderBy('name')
            ->limit(self::PRODUCT_LIMIT)
            ->pluck('name', 'id')
            ->all();
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
