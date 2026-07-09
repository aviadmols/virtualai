<?php

namespace App\Jobs;

use App\Domain\Storyboard\StoryboardVideoComposer;
use App\Models\StoryboardProject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * CombineStoryboardVideoJob — stitch all of a project's frame images into ONE MP4 via ffmpeg.
 *
 * Runs on the media queue (longer timeout than generations). Not tenant-aware, never charges.
 * Stores the result path + status on the project; any ffmpeg error is surfaced in final_video_meta
 * so the builder shows it. tries=1 (a stitch is deterministic — no retry storm on a bad input).
 */
final class CombineStoryboardVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;
    public int $timeout = 110;

    public function __construct(
        public readonly int $projectId,
        public readonly int $seconds,
        public readonly string $resolution,
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
            $path = $composer->compose($project, $this->seconds, $this->resolution);

            $project->update([
                'final_video_path' => $path,
                'final_video_status' => StoryboardProject::VIDEO_READY,
                'final_video_meta' => ['seconds' => $this->seconds, 'resolution' => $this->resolution],
            ]);
        } catch (Throwable $e) {
            $this->markFailed($project, $e->getMessage());

            throw $e;
        }
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
