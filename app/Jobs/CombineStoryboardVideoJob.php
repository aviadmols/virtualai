<?php

namespace App\Jobs;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CombineStoryboardVideoJob — build ONE MP4 from a project. Three modes:
 *
 * - REFERENCE: generate ONE coherent AI video from a PROMPT + the project's reference images
 *   (AtlasCloud reference-to-video). The admin controls the prompt, duration, structure (ratio) and
 *   resolution. Async: submit → re-dispatch to poll → download + store.
 * - ANIMATE: ensure every frame has a real AI motion clip (submit the missing ones via
 *   GenerateStoryboardClipJob), poll until they finish, then stitch the CLIPS into one film (ffmpeg).
 * - SLIDESHOW: a one-shot ffmpeg slideshow of the still frame images (fast, free preview).
 *
 * Runs on the media queue. Not tenant-aware, never charges (video is billed at generation, not here).
 * Any error is surfaced in final_video_meta so the builder shows it. tries=1.
 */
final class CombineStoryboardVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const MODE_REFERENCE = 'reference';
    public const MODE_ANIMATE = 'animate';
    public const MODE_SLIDESHOW = 'slideshow';

    public int $tries = 1;
    public int $timeout = 110;

    private const MAX_POLL_ATTEMPTS = 60;   // 60 × 15s ≈ 15 min for the render(s) to finish
    private const POLL_DELAY = 15;
    private const DEFAULT_RATIO = 'adaptive';

    public function __construct(
        public readonly int $projectId,
        public readonly string $mode,
        public readonly string $resolution,
        public readonly int $seconds,
        public readonly ?string $prompt = null,
        public readonly ?string $ratio = null,
        public readonly int $attempt = 0,
    ) {
        $this->onQueue((string) config('trayon.queues.media'));
    }

    public function handle(StoryboardVideoComposer $composer): void
    {
        $project = StoryboardProject::find($this->projectId);

        if ($project === null) {
            return;
        }

        try {
            match ($this->mode) {
                self::MODE_REFERENCE => $this->coordinateReference($project),
                self::MODE_SLIDESHOW => $this->finish($project, $composer->compose($project, $this->seconds, $this->resolution)),
                default => $this->coordinateAnimate($project, $composer),
            };
        } catch (Throwable $e) {
            $this->markFailed($project, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Generate ONE video from the story prompt + the project's reference images (reference-to-video).
     * First firing submits + stores the prediction id; later firings poll → download → store.
     */
    private function coordinateReference(StoryboardProject $project): void
    {
        $meta = is_array($project->final_video_meta) ? $project->final_video_meta : [];
        $router = app(VideoProviderRouter::class);

        if (! empty($meta['prediction_id'])) {
            $this->pollReference($project, $meta, $router);

            return;
        }

        $refUrls = $this->referenceImageUrls($project);
        if ($refUrls === []) {
            $this->markFailed($project, 'No reference images to generate from — add reference images to the project first.');

            return;
        }

        $config = app(AiOperationResolver::class)->for(AiOperation::KEY_STORYBOARD_CLIP);
        $client = $router->for($config->provider);
        $prompt = ($this->prompt !== null && $this->prompt !== '') ? $this->prompt : (string) $project->story_idea;

        $taskId = $client->submitTask($config->model, $prompt, $refUrls, [
            'resolution' => $this->resolution,
            'duration_seconds' => $this->seconds,
            'ratio' => ($this->ratio !== null && $this->ratio !== '') ? $this->ratio : ($project->aspect_ratio ?? self::DEFAULT_RATIO),
        ]);

        $project->update([
            'final_video_status' => StoryboardProject::VIDEO_GENERATING,
            'final_video_meta' => [
                'mode' => self::MODE_REFERENCE,
                'provider' => $config->provider,
                'model' => $config->model,
                'prediction_id' => $taskId,
                'resolution' => $this->resolution,
            ],
        ]);

        $this->reschedule();
    }

    /** Poll the in-flight reference-to-video prediction; on success download + store the final video. */
    private function pollReference(StoryboardProject $project, array $meta, VideoProviderRouter $router): void
    {
        $client = $router->for((string) ($meta['provider'] ?? ImageGenerationProvider::PROVIDER_ATLASCLOUD));

        try {
            $task = $client->pollTask((string) $meta['prediction_id']);
        } catch (Throwable $e) {
            $this->rescheduleOrFail($project, 'Video generation poll failed: '.$e->getMessage());

            return;
        }

        if ($client->succeeded($task)) {
            $url = data_get($task, 'content.video_url');
            $bytes = (is_string($url) && $url !== '') ? $client->downloadVideo($url) : null;

            if ($bytes === null) {
                $this->rescheduleOrFail($project, 'Generated video could not be downloaded.');

                return;
            }

            $stored = app(MediaStorage::class)->storeStoryboardVideo($project->id, $bytes);
            $project->update([
                'final_video_path' => $stored->path,
                'final_video_status' => StoryboardProject::VIDEO_READY,
                'final_video_meta' => array_merge($meta, ['status' => 'ready']),
            ]);

            return;
        }

        if (in_array((string) ($task['status'] ?? ''), VideoGenerationProvider::TERMINAL_FAILURE, true)) {
            $this->markFailed($project, (string) (data_get($task, 'error.message') ?: 'Video generation failed.'));

            return;
        }

        $this->rescheduleOrFail($project, 'Video generation timed out.');
    }

    /**
     * Submit a clip for every frame that still needs one, poll by re-dispatching until none are
     * rendering, then stitch the ready clips into one film.
     */
    private function coordinateAnimate(StoryboardProject $project, StoryboardVideoComposer $composer): void
    {
        if (! $project->frames()->whereNotNull('image_path')->exists()) {
            $this->markFailed($project, 'No generated frame images to animate.');

            return;
        }

        foreach ($project->frames()->whereNotNull('image_path')->get() as $frame) {
            $needsClip = $frame->video_path === null
                && $frame->video_status !== StoryboardFrame::VIDEO_GENERATING
                && $frame->video_status !== StoryboardFrame::VIDEO_FAILED;

            if ($needsClip) {
                $frame->update(['video_status' => StoryboardFrame::VIDEO_GENERATING, 'video_poll_attempts' => 0]);
                GenerateStoryboardClipJob::dispatch($frame->id);
            }
        }

        $rendering = $project->frames()
            ->whereNotNull('image_path')
            ->whereNull('video_path')
            ->where('video_status', StoryboardFrame::VIDEO_GENERATING)
            ->exists();

        if ($rendering && $this->attempt < self::MAX_POLL_ATTEMPTS) {
            $this->reschedule();

            return;
        }

        // No clip is still rendering (or we ran out of patience) — stitch whatever is ready.
        if ($project->frames()->whereNotNull('video_path')->count() === 0) {
            $reasons = $this->clipFailureReasons($project);

            // Detailed reason in the log AND on the builder card, so the real cause is visible
            // (usually: the video provider could not fetch the frame image, a bad/retired model id,
            // or a missing provider key). Switching the clip provider to AtlasCloud avoids the
            // image-reachability failure since it sends the frame inline as base64.
            Log::warning('storyboard.combine.all_clips_failed', [
                'project_id' => $project->id,
                'mode' => $this->mode,
                'reasons' => $reasons !== '' ? $reasons : 'no per-frame error recorded',
            ]);

            $this->markFailed($project, trim('All frame clips failed to render. '.$reasons));

            return;
        }

        $this->finish($project, $composer->concatClips($project, $this->resolution));
    }

    /** Signed URLs of the project's uploaded reference images (@image1..N), in order. */
    private function referenceImageUrls(StoryboardProject $project): array
    {
        $media = app(MediaStorage::class);

        return $project->assets()
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->get()
            ->map(fn ($asset): ?string => $media->signedUrl($asset->file_path))
            ->filter()
            ->values()
            ->all();
    }

    /** Aggregate each frame's clip-failure reason (video_meta.error) for the log + on-screen error. */
    private function clipFailureReasons(StoryboardProject $project): string
    {
        return $project->frames()
            ->whereNotNull('image_path')
            ->orderBy('frame_number')
            ->get()
            ->map(function (StoryboardFrame $frame): ?string {
                $error = is_array($frame->video_meta) ? ($frame->video_meta['error'] ?? null) : null;

                return $error ? 'Frame #'.$frame->frame_number.': '.$error : null;
            })
            ->filter()
            ->implode(' | ');
    }

    private function finish(StoryboardProject $project, string $path): void
    {
        $project->update([
            'final_video_path' => $path,
            'final_video_status' => StoryboardProject::VIDEO_READY,
            'final_video_meta' => ['mode' => $this->mode, 'resolution' => $this->resolution],
        ]);
    }

    /** Re-dispatch this job to poll again (same params, next attempt). */
    private function reschedule(): void
    {
        self::dispatch($this->projectId, $this->mode, $this->resolution, $this->seconds, $this->prompt, $this->ratio, $this->attempt + 1)
            ->delay(now()->addSeconds(self::POLL_DELAY));
    }

    /** Reschedule while attempts remain, else fail with the given reason. */
    private function rescheduleOrFail(StoryboardProject $project, string $reason): void
    {
        if ($this->attempt < self::MAX_POLL_ATTEMPTS) {
            $this->reschedule();

            return;
        }

        $this->markFailed($project, $reason);
    }

    public function failed(Throwable $e): void
    {
        $project = StoryboardProject::find($this->projectId);

        if ($project !== null && $project->final_video_status === StoryboardProject::VIDEO_GENERATING) {
            $this->markFailed($project, $e->getMessage());
        }
    }

    private function markFailed(?StoryboardProject $project, string $error): void
    {
        $project?->update([
            'final_video_status' => StoryboardProject::VIDEO_FAILED,
            'final_video_meta' => ['error' => $error],
        ]);
    }
}
