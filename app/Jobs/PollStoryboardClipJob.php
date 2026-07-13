<?php

namespace App\Jobs;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Models\StoryboardFrame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * PollStoryboardClipJob — polls one frame's async Seedance clip to completion, re-dispatching with
 * a delay while it renders (each firing short — fits the media worker timeout). On success it
 * downloads the mp4, stores it, and records the render time. Bounded by MAX_ATTEMPTS. Never charges.
 */
final class PollStoryboardClipJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;

    public int $timeout = 120;

    private const MAX_ATTEMPTS = 40;

    private const DELAY_SECONDS = 15;

    private const VIDEO_MIME = 'video/mp4';

    private const MS_PER_SECOND = 1000;

    public function __construct(
        public readonly int $frameId,
    ) {
        $this->onQueue((string) config('trayon.queues.media'));
    }

    public function handle(VideoProviderRouter $router, MediaStorage $media): void
    {
        $frame = StoryboardFrame::find($this->frameId);

        if ($frame === null || $frame->video_status !== StoryboardFrame::VIDEO_GENERATING) {
            return;
        }

        if ($frame->video_task_id === null || $frame->video_task_id === '') {
            $this->markFailed($frame, 'Video task id missing.');

            return;
        }

        $meta = is_array($frame->video_meta) ? $frame->video_meta : [];
        $baseUrl = $meta['base_url'] ?? null;
        // Resolve the SAME upstream client that submitted this task (recorded at submit time).
        $video = $router->for((string) ($meta['provider'] ?? ImageGenerationProvider::PROVIDER_BYTEPLUS));

        try {
            $task = $video->pollTask($frame->video_task_id, $baseUrl);
        } catch (Throwable $e) {
            $this->reschedule($frame, $e->getMessage());

            return;
        }

        if ($video->succeeded($task)) {
            $this->finish($frame, $task, $video, $media);

            return;
        }

        if (in_array((string) ($task['status'] ?? ''), VideoGenerationProvider::TERMINAL_FAILURE, true)) {
            $this->markFailed($frame, $this->taskError($task));

            return;
        }

        $this->reschedule($frame, null);
    }

    private function finish(StoryboardFrame $frame, array $task, VideoGenerationProvider $video, MediaStorage $media): void
    {
        $url = data_get($task, 'content.video_url');
        if (! is_string($url) || $url === '') {
            $this->markFailed($frame, 'Succeeded task carried no video url.');

            return;
        }

        $bytes = $video->downloadVideo($url);
        if ($bytes === null) {
            $this->reschedule($frame, 'Could not download the clip (will retry).');

            return;
        }

        $stored = $media->storeStoryboardFrame($frame->project_id, $frame->id, $bytes, self::VIDEO_MIME);

        $frame->update([
            'video_status' => StoryboardFrame::VIDEO_READY,
            'video_path' => $stored->path,
            'video_duration_ms' => $this->renderMs($task),
            'video_cost_micro_usd' => $this->cost($frame, $task),
        ]);
    }

    /**
     * The REAL cost the upstream billed for this clip when it returns one (Kling answers with
     * billing[]), else the per-clip price hint recorded at submit time. Never charges — this is a
     * recorded cost, not a ledger row.
     *
     * @param  array<string,mixed>  $task
     */
    private function cost(StoryboardFrame $frame, array $task): ?int
    {
        $real = data_get($task, VideoGenerationProvider::KEY_COST.'.'.VideoGenerationProvider::KEY_COST_MICRO_USD);

        if (is_int($real) && $real > 0) {
            return $real;
        }

        $hint = is_array($frame->video_meta) ? ($frame->video_meta['cost'] ?? null) : null;

        return is_numeric($hint) ? (int) $hint : null;
    }

    private function renderMs(array $task): ?int
    {
        $created = (int) ($task['created_at'] ?? 0);
        $updated = (int) ($task['updated_at'] ?? 0);

        return ($created > 0 && $updated >= $created) ? ($updated - $created) * self::MS_PER_SECOND : null;
    }

    private function reschedule(StoryboardFrame $frame, ?string $error): void
    {
        $attempts = $frame->video_poll_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->markFailed($frame, $error ?? 'Clip generation timed out.');

            return;
        }

        $frame->update(['video_poll_attempts' => $attempts]);

        self::dispatch($frame->id)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    private function taskError(array $task): string
    {
        $message = data_get($task, 'error.message');

        return is_string($message) && $message !== '' ? $message : 'Clip generation failed.';
    }

    private function markFailed(StoryboardFrame $frame, string $message): void
    {
        $frame->update([
            'video_status' => StoryboardFrame::VIDEO_FAILED,
            'video_meta' => array_merge($frame->video_meta ?? [], ['error' => $message]),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $frame = StoryboardFrame::find($this->frameId);

        if ($frame !== null && $frame->video_status === StoryboardFrame::VIDEO_GENERATING) {
            $frame->update(['video_status' => StoryboardFrame::VIDEO_FAILED]);
        }
    }
}
