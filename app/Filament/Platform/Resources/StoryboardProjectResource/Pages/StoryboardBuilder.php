<?php

namespace App\Filament\Platform\Resources\StoryboardProjectResource\Pages;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\StoryboardTextCaller;
use App\Domain\Credits\CreditMath;
use App\Domain\Media\MediaStorage;
use App\Domain\Storyboard\StoryboardFrameGenerator;
use App\Filament\Platform\Resources\StoryboardProjectResource;
use App\Models\AiOperation;
use App\Filament\Platform\Resources\StoryboardProjectResource\Pages\Concerns\StartsStoryboardPipeline;
use App\Jobs\CombineStoryboardVideoJob;
use App\Jobs\GenerateStoryboardClipJob;
use App\Jobs\GenerateStoryboardFrameJob;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
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
    use StartsStoryboardPipeline;

    // === CONSTANTS ===
    protected static string $resource = StoryboardProjectResource::class;

    protected static string $view = 'filament.platform.storyboard.builder';

    private const MS_SECOND_THRESHOLD = 1000;

    // Combined-video options (output height per key; width derived from the project aspect).
    private const RESOLUTIONS = ['720p' => '720p', '1080p' => '1080p'];
    private const DEFAULT_RESOLUTION = '1080p';
    private const DEFAULT_SECONDS = 15;

    // Inline frame-prompt editor state (no modal — a per-frame inline form).
    public ?int $editingFrameId = null;

    public string $editPrompt = '';

    public string $editNegative = '';

    // Inline AI "improve prompt" state (an instruction the LLM applies to the frame's prompt).
    public ?int $improvingFrameId = null;

    public string $improveInstruction = '';

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
        // One "Generate" dropdown: run the pipeline, generate all frames, or combine into one video.
        return [
            ActionGroup::make([
                Action::make('runPipeline')
                    ->label(__('platform.storyboard.run'))
                    ->icon('heroicon-o-play')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $this->startStoryboardPipeline($this->record);
                        Notification::make()->success()->title(__('platform.storyboard.run_started'))->send();
                    }),
                Action::make('generateAll')
                    ->label(__('platform.storyboard.generate_all'))
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (): bool => $this->record->frames()->exists())
                    ->action(fn () => $this->generateAllFrames()),
                Action::make('combineVideo')
                    ->label(__('platform.storyboard.combine_video'))
                    ->icon('heroicon-o-film')
                    ->visible(fn (): bool => $this->record->frames()->whereNotNull('image_path')->exists())
                    ->form([
                        Select::make('mode')
                            ->label(__('platform.storyboard.combine_mode'))
                            ->options([
                                CombineStoryboardVideoJob::MODE_ANIMATE => __('platform.storyboard.combine_mode_animate'),
                                CombineStoryboardVideoJob::MODE_SLIDESHOW => __('platform.storyboard.combine_mode_slideshow'),
                            ])
                            ->default(CombineStoryboardVideoJob::MODE_ANIMATE)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->helperText(__('platform.storyboard.combine_mode_help'))
                            ->required(),
                        Select::make('resolution')
                            ->label(__('platform.storyboard.combine_resolution'))
                            ->options(self::RESOLUTIONS)
                            ->default(self::DEFAULT_RESOLUTION)
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('seconds')
                            ->label(__('platform.storyboard.combine_seconds'))
                            ->helperText(__('platform.storyboard.combine_seconds_help'))
                            ->numeric()
                            ->minValue(3)
                            ->maxValue(120)
                            ->default(fn (): int => max(3, ((int) $this->record->duration_seconds) ?: self::DEFAULT_SECONDS))
                            ->visible(fn (Get $get): bool => $get('mode') === CombineStoryboardVideoJob::MODE_SLIDESHOW)
                            ->required(fn (Get $get): bool => $get('mode') === CombineStoryboardVideoJob::MODE_SLIDESHOW),
                    ])
                    ->action(function (array $data): void {
                        $this->record->update([
                            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
                            'final_video_meta' => null,
                        ]);
                        CombineStoryboardVideoJob::dispatch(
                            $this->record->id,
                            (string) $data['mode'],
                            (string) $data['resolution'],
                            (int) ($data['seconds'] ?? $this->record->duration_seconds ?: self::DEFAULT_SECONDS),
                        );
                        Notification::make()->success()->title(__('platform.storyboard.combine_started'))->send();
                    }),
            ])
                ->label(__('platform.storyboard.generate'))
                ->icon('heroicon-o-sparkles')
                ->button(),
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

    public function startImprove(int $frameId): void
    {
        $frame = $this->frame($frameId);
        if ($frame === null) {
            return;
        }

        $this->improvingFrameId = $frame->id;
        $this->improveInstruction = '';
        $this->cancelEdit(); // only one inline editor open at a time
    }

    public function cancelImprove(): void
    {
        $this->improvingFrameId = null;
        $this->improveInstruction = '';
    }

    /**
     * AI-rewrite the frame's image_prompt from a plain instruction ("give the king white hair"),
     * via the DB-configured storyboard_improve_prompt operation. Runs inline (a few seconds), then
     * optionally regenerates the image. Never charges.
     */
    public function applyImprove(bool $regenerate = false): void
    {
        $frame = $this->frame((int) $this->improvingFrameId);
        $instruction = trim($this->improveInstruction);

        if ($frame === null || $instruction === '') {
            return;
        }

        try {
            $config = app(AiOperationResolver::class)->for(AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT);
            $result = app(StoryboardTextCaller::class)->extract($config, [
                'original' => (string) $frame->image_prompt,
                'instruction' => $instruction,
            ]);
            $improved = trim((string) ($result->json['improved_prompt'] ?? ''));
        } catch (\Throwable $e) {
            Notification::make()->danger()->title(__('platform.storyboard.improve_failed'))->body($e->getMessage())->send();

            return;
        }

        if ($improved === '') {
            Notification::make()->danger()->title(__('platform.storyboard.improve_failed'))->send();

            return;
        }

        $frame->update(['image_prompt' => $improved]);
        $this->cancelImprove();
        Notification::make()->success()->title(__('platform.storyboard.improve_done'))->send();

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

    /** The combined video (all frames stitched into one MP4), for the top-of-builder card. */
    public function getFinalVideo(): ?array
    {
        $status = $this->record->final_video_status;
        if ($status === null) {
            return null;
        }

        $media = app(MediaStorage::class);

        return [
            'generating' => $status === StoryboardProject::VIDEO_GENERATING,
            'ready' => $status === StoryboardProject::VIDEO_READY,
            'failed' => $status === StoryboardProject::VIDEO_FAILED,
            'url' => $this->record->final_video_path !== null ? $media->signedUrl($this->record->final_video_path) : null,
            'error' => is_array($this->record->final_video_meta) ? ($this->record->final_video_meta['error'] ?? null) : null,
        ];
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
