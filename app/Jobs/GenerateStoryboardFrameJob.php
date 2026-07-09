<?php

namespace App\Jobs;

use App\Domain\Storyboard\StoryboardFrameGenerator;
use App\Models\StoryboardFrame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * GenerateStoryboardFrameJob — generates (or regenerates) ONE storyboard frame's image off-request.
 *
 * NOT tenant-aware and NOT on the money path. One frame per job so a fleet of frames generates in
 * parallel and a single failure never blocks the rest. tries=1 (no silent re-spend); generations
 * queue. An optional edit instruction drives a regenerate/edit.
 */
final class GenerateStoryboardFrameJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;
    public int $timeout = 70;

    public function __construct(
        public readonly int $frameId,
        public readonly ?string $editInstruction = null,
    ) {
        $this->onQueue((string) config('trayon.queues.generations'));
    }

    public function handle(StoryboardFrameGenerator $generator): void
    {
        $frame = StoryboardFrame::find($this->frameId);

        if ($frame === null) {
            return;
        }

        $generator->generate($frame, $this->editInstruction);
    }

    public function failed(Throwable $e): void
    {
        $frame = StoryboardFrame::find($this->frameId);

        if ($frame !== null && $frame->status !== StoryboardFrame::STATUS_READY) {
            $frame->update(['status' => StoryboardFrame::STATUS_FAILED]);
        }
    }
}
