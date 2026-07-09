<?php

namespace App\Jobs;

use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Models\StoryboardFrame;
use App\Models\StoryboardProject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * CombineStoryboardVideoJob — build ONE MP4 from a project. Two modes:
 *
 * - ANIMATE (default): ensure every frame has a real AI motion clip (submit the missing ones via
 *   GenerateStoryboardClipJob), poll by re-dispatching until they finish, then stitch the CLIPS into
 *   one animated film. A real movie, not a slideshow.
 * - SLIDESHOW: a one-shot ffmpeg slideshow of the still frame images (fast, free preview).
 *
 * Runs on the media queue. Not tenant-aware, never charges (clips are billed at generation). Any
 * error is surfaced in final_video_meta so the builder shows it. tries=1.
 */
final class CombineStoryboardVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const MODE_ANIMATE = 'animate';
    public const MODE_SLIDESHOW = 'slideshow';

    public int $tries = 1;
    public int $timeout = 110;

    private const MAX_POLL_ATTEMPTS = 60;   // 60 × 15s ≈ 15 min for all clips to render
    private const POLL_DELAY = 15;

    public function __construct(
        public readonly int $projectId,
        public readonly string $mode,
        public readonly string $resolution,
        public readonly int $seconds,
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
            if ($this->mode === self::MODE_SLIDESHOW) {
                $this->finish($project, $composer->compose($project, $this->seconds, $this->resolution));

                return;
            }

            $this->coordinateAnimate($project, $composer);
        } catch (Throwable $e) {
            $this->markFailed($project, $e->getMessage());

            throw $e;
        }
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
            self::dispatch($this->projectId, $this->mode, $this->resolution, $this->seconds, $this->attempt + 1)
                ->delay(now()->addSeconds(self::POLL_DELAY));

            return;
        }

        // No clip is still rendering (or we ran out of patience) — stitch whatever is ready.
        if ($project->frames()->whereNotNull('video_path')->count() === 0) {
            $this->markFailed($project, 'All frame clips failed to render.');

            return;
        }

        $this->finish($project, $composer->concatClips($project, $this->resolution));
    }

    private function finish(StoryboardProject $project, string $path): void
    {
        $project->update([
            'final_video_path' => $path,
            'final_video_status' => StoryboardProject::VIDEO_READY,
            'final_video_meta' => ['mode' => $this->mode, 'resolution' => $this->resolution],
        ]);
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
