<?php

namespace App\Jobs;

use App\Domain\Storyboard\StoryboardClipGenerator;
use App\Models\StoryboardFrame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * GenerateStoryboardClipJob — submits the video-clip task for one frame, then hands off to the
 * poller. Fast (just the submit); the multi-minute render is polled by PollStoryboardClipJob.
 * Not tenant-aware, never charges. tries=1.
 */
final class GenerateStoryboardClipJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;
    public int $timeout = 70;

    private const FIRST_POLL_DELAY = 15;

    public function __construct(
        public readonly int $frameId,
    ) {
        $this->onQueue((string) config('trayon.queues.generations'));
    }

    public function handle(StoryboardClipGenerator $generator): void
    {
        $frame = StoryboardFrame::find($this->frameId);

        if ($frame === null || $frame->image_path === null) {
            return;
        }

        if ($generator->submit($frame)) {
            PollStoryboardClipJob::dispatch($frame->id)->delay(now()->addSeconds(self::FIRST_POLL_DELAY));
        }
    }

    public function failed(Throwable $e): void
    {
        $frame = StoryboardFrame::find($this->frameId);

        if ($frame !== null && $frame->video_status === StoryboardFrame::VIDEO_GENERATING) {
            $frame->update(['video_status' => StoryboardFrame::VIDEO_FAILED]);
        }
    }
}
