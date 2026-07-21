<?php

namespace App\Filament\Merchant\Widgets;

use App\Domain\Banners\BannerGenerationRequest;
use App\Domain\Banners\BannerService;
use App\Domain\Banners\InvalidBannerException;
use App\Domain\Banners\StartBannerGeneration;
use App\Domain\Media\MediaStorage;
use App\Models\Banner;
use App\Models\BannerAsset;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * The LIVE candidate gallery under the banner editor (a footer widget). It polls WHILE any
 * candidate is still queued/generating and stops the moment none are — so the merchant watches a
 * generation move from "Generating…" to a thumbnail (or a clear failure) without ever reloading
 * the page. This is the surface that fixes the "Generate did nothing" symptom.
 *
 * Selecting a candidate (or retrying a failed one) routes through the validated writer /
 * money-path entry point; the editor's record is refreshed via an event so the header actions
 * (Activate) immediately see the chosen artwork. The banner is injected by the EditRecord page
 * (InteractsWithRecord::getWidgetData() → the public $record prop).
 */
class BannerCandidatesWidget extends Widget
{
    // === CONSTANTS ===
    protected static string $view = 'filament.merchant.widgets.banner-candidates';

    protected int|string|array $columnSpan = 'full';

    // Poll cadence while a candidate is in flight; the newest N attempts to show.
    private const POLL_INTERVAL = '4s';

    private const MAX_CANDIDATES = 12;

    // The banner being edited (spread in by the EditRecord page's getWidgetData()).
    public ?Banner $record = null;

    /** The recent generation attempts for this banner, newest first (re-queried each render). */
    #[Computed]
    public function candidates(): Collection
    {
        if ($this->record === null) {
            return collect();
        }

        return $this->record->assets()->latest('id')->limit(self::MAX_CANDIDATES)->get();
    }

    /** True while any candidate is still queued/processing — drives the conditional poll. */
    public function isWorking(): bool
    {
        return $this->candidates->contains(
            static fn (BannerAsset $a): bool => in_array($a->status, [BannerAsset::STATUS_PENDING, BannerAsset::STATUS_PROCESSING], true),
        );
    }

    /** The wire:poll interval while work is in flight, or null (no poll) once idle. */
    public function pollInterval(): ?string
    {
        return $this->isWorking() ? self::POLL_INTERVAL : null;
    }

    /** The public thumbnail URL for a succeeded candidate (null when it has no stored image). */
    public function thumbUrl(BannerAsset $asset): ?string
    {
        return filled($asset->image_path) ? app(MediaStorage::class)->publicUrl($asset->image_path) : null;
    }

    /** The stored human failure reason for a failed/cancelled candidate, if any. */
    public function failureMessage(BannerAsset $asset): ?string
    {
        return is_array($asset->meta) ? ($asset->meta[BannerAsset::META_FAILURE_MESSAGE] ?? null) : null;
    }

    /** Whether a candidate is the banner's currently chosen artwork. */
    public function isSelected(BannerAsset $asset): bool
    {
        return (int) $asset->getKey() === (int) ($this->record?->selected_asset_id ?? 0);
    }

    /**
     * A new generation started elsewhere on the page (the editor's "Generate image" header
     * action). Receiving the event re-renders the widget, so the fresh pending candidate shows
     * and the conditional poll kicks in. The empty body is intentional.
     */
    #[On('banner-generation-started')]
    public function onGenerationStarted(): void {}

    /** Choose a candidate as the banner artwork (the validated writer), then tell the editor. */
    public function useAsset(int $assetId): void
    {
        $asset = $this->record?->assets()->find($assetId);
        if ($asset === null) {
            return;
        }

        try {
            app(BannerService::class)->selectAsset($this->record, $asset);
            $this->record->refresh();
            // The editor listens and refreshes its record so Activate sees the new artwork.
            $this->dispatch('banner-artwork-selected');
            Notification::make()->success()->title(__('banners.candidates.selected'))->send();
        } catch (InvalidBannerException $e) {
            Notification::make()->danger()->title(__('banners.errors.'.$e->reason))->send();
        }
    }

    /** Re-run a generation from a failed candidate's stored brief (a fresh attempt/candidate). */
    public function retry(int $assetId): void
    {
        $asset = $this->record?->assets()->find($assetId);
        if ($asset === null || ! filled($asset->brief)) {
            return;
        }

        try {
            app(StartBannerGeneration::class)->handle(new BannerGenerationRequest(
                banner: $this->record,
                brief: (string) $asset->brief,
                clientRequestId: (string) Str::uuid(),
                // Carry the original style forward so a retry keeps the same look.
                styleId: ($asset->meta[BannerAsset::META_STYLE_ID] ?? null) !== null
                    ? (int) $asset->meta[BannerAsset::META_STYLE_ID]
                    : null,
            ));
            Notification::make()->success()->title(__('banners.generate.queued'))->send();
        } catch (\Throwable $e) {
            Log::warning('banner retry failed to start', ['asset_id' => $assetId, 'error' => $e->getMessage()]);
            Notification::make()->danger()->title(__('banners.generate.failed'))->send();
        }
    }
}
