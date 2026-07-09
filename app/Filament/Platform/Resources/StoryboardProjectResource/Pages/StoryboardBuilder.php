<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages;

use App\Domain\Credits\CreditMath;
use App\Domain\Media\MediaStorage;
use App\Domain\Storyboard\StoryboardFrameGenerator;
use App\Filament\Platform\Resources\StoryboardProjectResource;
use App\Jobs\GenerateStoryboardClipJob;
use App\Jobs\GenerateStoryboardFrameJob;
use App\Jobs\RunStoryboardPipelineJob;
use App\Domain\Storyboard\StoryboardStep;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use App\Models\StoryboardStepRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * StoryboardBuilder — the working surface for one project: run the pipeline, watch step progress,
 * and generate + edit each frame (regenerate, edit the prompt, pick a version, approve, lock). All
 * mutations guard that the frame belongs to THIS project. Admin-only; never charges. The gallery
 * polls so async generations appear without a manual refresh.
 */
class StoryboardBuilder extends Page
{
    use InteractsWithRecord;

    // === CONSTANTS ===
    protected static string $resource = StoryboardProjectResource::class;

    protected static string $view = 'filament.platform.storyboard.builder';

    private const MS_SECOND_THRESHOLD = 1000;

    // Inline frame-prompt editor state (no modal — a per-frame inline form).
    public ?int $editingFrameId = null;

    public string $editPrompt = '';

    public string $editNegative = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return $this->record->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runPipeline')
                ->label(__('platform.storyboard.run'))
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->action(function (): void {
                    // Pre-create the step rows as pending so the pipeline is visible IMMEDIATELY
                    // (before a worker picks the job up) — the admin sees it started, not a blank.
                    foreach (StoryboardStep::TEXT_STEPS as $stepKey) {
                        $this->record->stepRuns()->updateOrCreate(
                            ['step_key' => $stepKey],
                            ['status' => StoryboardStepRun::STATUS_PENDING, 'error' => null, 'output' => null, 'duration_ms' => null],
                        );
                    }

                    $this->record->update(['status' => StoryboardProject::STATUS_RUNNING]);
                    RunStoryboardPipelineJob::dispatch($this->record->id);
                    Notification::make()->success()->title(__('platform.storyboard.run_started'))->send();
                }),
            Action::make('generateAll')
                ->label(__('platform.storyboard.generate_all'))
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => $this->record->frames()->exists())
                ->action(fn () => $this->generateAllFrames()),
            Action::make('generateAllClips')
                ->label(__('platform.storyboard.generate_all_clips'))
                ->icon('heroicon-o-video-camera')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->frames()->whereNotNull('image_path')->exists())
                ->action(fn () => $this->generateAllClips()),
        ];
    }

    public function generateAllFrames(): void
    {
        $frames = $this->record->frames()->where('is_locked', false)->get();

        foreach ($frames as $frame) {
            $frame->update(['status' => StoryboardFrame::STATUS_GENERATING]);
            GenerateStoryboardFrameJob::dispatch($frame->id);
        }

        Notification::make()->success()->title(__('platform.storyboard.generating_frames', ['count' => $frames->count()]))->send();
    }

    public function generateFrame(int $frameId): void
    {
        $frame = $this->frame($frameId);
        if ($frame === null || $frame->is_locked) {
            return;
        }

        $frame->update(['status' => StoryboardFrame::STATUS_GENERATING]);
        GenerateStoryboardFrameJob::dispatch($frame->id);
    }

    /** Animate a frame's image into a video clip (image-to-video). Needs a generated image. */
    public function generateClip(int $frameId): void
    {
        $frame = $this->frame($frameId);
        if ($frame === null || $frame->image_path === null) {
            return;
        }

        $frame->update(['video_status' => StoryboardFrame::VIDEO_GENERATING, 'video_poll_attempts' => 0]);
        GenerateStoryboardClipJob::dispatch($frame->id);
    }

    public function generateAllClips(): void
    {
        $frames = $this->record->frames()->whereNotNull('image_path')->where('is_locked', false)->get();

        foreach ($frames as $frame) {
            $frame->update(['video_status' => StoryboardFrame::VIDEO_GENERATING, 'video_poll_attempts' => 0]);
            GenerateStoryboardClipJob::dispatch($frame->id);
        }

        Notification::make()->success()->title(__('platform.storyboard.generating_clips', ['count' => $frames->count()]))->send();
    }

    public function approveFrame(int $frameId): void
    {
        $frame = $this->frame($frameId);
        $frame?->update(['is_approved' => ! $frame->is_approved]);
    }

    public function toggleLock(int $frameId): void
    {
        $frame = $this->frame($frameId);
        $frame?->update(['is_locked' => ! $frame->is_locked]);
    }

    public function selectVersion(int $frameId, int $versionId): void
    {
        $frame = $this->frame($frameId);
        $version = $frame?->versions()->find($versionId);

        if ($frame !== null && $version !== null) {
            app(StoryboardFrameGenerator::class)->selectVersion($frame, $version);
        }
    }

    public function startEdit(int $frameId): void
    {
        $frame = $this->frame($frameId);
        if ($frame === null) {
            return;
        }

        $this->editingFrameId = $frame->id;
        $this->editPrompt = (string) $frame->image_prompt;
        $this->editNegative = (string) $frame->negative_prompt;
    }

    public function cancelEdit(): void
    {
        $this->editingFrameId = null;
        $this->editPrompt = '';
        $this->editNegative = '';
    }

    public function saveEdit(bool $regenerate = false): void
    {
        $frame = $this->frame((int) $this->editingFrameId);
        if ($frame === null) {
            return;
        }

        $frame->update(['image_prompt' => $this->editPrompt, 'negative_prompt' => $this->editNegative]);
        $this->cancelEdit();

        if ($regenerate && ! $frame->is_locked) {
            $frame->update(['status' => StoryboardFrame::STATUS_GENERATING]);
            GenerateStoryboardFrameJob::dispatch($frame->id);
        }
    }

    /** The pipeline step-run log for the progress panel. @return array<int,array<string,mixed>> */
    public function getSteps(): array
    {
        return $this->record->stepRuns()
            ->orderBy('id')
            ->get()
            ->map(fn ($run): array => [
                'label' => __('platform.storyboard.step.'.$run->step_key),
                'status' => $run->status,
                'error' => $run->error,
                'duration' => $run->duration_ms !== null ? $this->duration($run->duration_ms) : null,
                'model' => $run->model,
                'cost' => $run->cost_micro_usd !== null ? '$'.number_format(CreditMath::microToUsd($run->cost_micro_usd), 4) : null,
                'output' => is_array($run->output)
                    ? json_encode($run->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
            ])
            ->all();
    }

    /** Frames formatted for the gallery (signed image urls + versions). @return array<int,array<string,mixed>> */
    public function getFrames(): array
    {
        $media = app(MediaStorage::class);

        return $this->record->frames()->with('versions')->get()->map(fn (StoryboardFrame $f): array => [
            'id' => $f->id,
            'number' => $f->frame_number,
            'time' => $f->start_second.'–'.$f->end_second.'s',
            'description' => $f->description,
            'prompt' => $f->image_prompt,
            'textOverlay' => $f->text_overlay,
            'status' => $f->status,
            'generating' => $f->status === StoryboardFrame::STATUS_GENERATING,
            'failed' => $f->status === StoryboardFrame::STATUS_FAILED,
            'error' => is_array($f->meta) ? ($f->meta['error'] ?? null) : null,
            'videoError' => is_array($f->video_meta) ? ($f->video_meta['error'] ?? null) : null,
            'approved' => $f->is_approved,
            'locked' => $f->is_locked,
            'imageUrl' => $f->image_path !== null ? $media->signedUrl($f->image_path) : null,
            'videoUrl' => $f->video_path !== null ? $media->signedUrl($f->video_path) : null,
            'videoGenerating' => $f->video_status === StoryboardFrame::VIDEO_GENERATING,
            'videoFailed' => $f->video_status === StoryboardFrame::VIDEO_FAILED,
            'versions' => $f->versions->map(fn ($v): array => [
                'id' => $v->id,
                'number' => $v->version_number,
                'selected' => $v->is_selected,
                'url' => $v->image_path !== null ? $media->signedUrl($v->image_path) : null,
            ])->all(),
        ])->all();
    }

    public function getVisualBible(): ?array
    {
        return $this->record->pipeline[StoryboardProject::PIPE_VISUAL_BIBLE] ?? null;
    }

    /**
     * The project's tagged reference images for the @-mention picker: type @ in a prompt and pick
     * one to insert its @tag (the generator attaches the matching image). @return array<int,array<string,mixed>>
     */
    public function getAssetTags(): array
    {
        $media = app(MediaStorage::class);

        return $this->record->assets()
            ->whereNotNull('file_path')
            ->get()
            ->map(fn ($asset): array => [
                'tag' => (string) $asset->tag,
                'url' => $media->signedUrl($asset->file_path),
            ])
            ->all();
    }

    /** The REAL total cost so far: pipeline steps + frame images + video clips (display only). */
    public function getTotalCost(): ?string
    {
        $total = (int) $this->record->stepRuns()->sum('cost_micro_usd')
            + (int) $this->record->frames()->sum('image_cost_micro_usd')
            + (int) $this->record->frames()->sum('video_cost_micro_usd');

        return $total > 0 ? '$'.number_format(CreditMath::microToUsd($total), 4) : null;
    }

    public function getProjectStatus(): string
    {
        return $this->record->status;
    }

    private function frame(int $frameId): ?StoryboardFrame
    {
        // Guard: only this project's frames are mutable from this page.
        return $this->record->frames()->whereKey($frameId)->first();
    }

    private function duration(int $ms): string
    {
        return $ms >= self::MS_SECOND_THRESHOLD
            ? number_format($ms / self::MS_SECOND_THRESHOLD, 1).' s'
            : $ms.' ms';
    }
}
