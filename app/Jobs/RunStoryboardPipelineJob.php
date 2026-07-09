<?php

namespace App\Jobs;

use App\Domain\Storyboard\StoryboardPipeline;
use App\Models\StoryboardProject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * RunStoryboardPipelineJob — runs a project's text pre-production pipeline off the request.
 *
 * NOT tenant-aware and NOT on the money path. The five text steps are fast (a small text model),
 * so they run in one firing on the media queue (120s worker timeout — ample). tries=1: a re-run
 * is an explicit admin action, never a silent queue retry.
 */
final class RunStoryboardPipelineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;
    public int $timeout = 115; // under the 120s media-queue worker timeout

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue((string) config('trayon.queues.media'));
    }

    public function handle(StoryboardPipeline $pipeline): void
    {
        $project = StoryboardProject::find($this->projectId);

        if ($project === null) {
            return;
        }

        $pipeline->run($project);
    }

    public function failed(Throwable $e): void
    {
        $project = StoryboardProject::find($this->projectId);

        if ($project !== null && $project->status !== StoryboardProject::STATUS_READY) {
            $project->update(['status' => StoryboardProject::STATUS_FAILED]);
        }
    }
}
