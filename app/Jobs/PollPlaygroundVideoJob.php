<?php

namespace App\Jobs;

use App\Domain\Ai\Contracts\VideoGenerationProvider;
use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Media\MediaStorage;
use App\Models\PlaygroundRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * PollPlaygroundVideoJob — polls ONE async BytePlus/Seedance video task to completion.
 *
 * Re-dispatches itself with a delay while the task is queued/running, so each firing is short and
 * fits the media-queue worker timeout no matter how long the video takes. On success it downloads
 * the MP4, stores it, and records the render time (the task's created->updated span) + the
 * flat-rate cost. Bounded by MAX_ATTEMPTS so a stuck task ends as a timeout failure — never an
 * infinite loop. Not tenant-aware, never charges.
 */
final class PollPlaygroundVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;
    public int $timeout = 120; // MEDIA_TIMEOUT — room to download the mp4

    private const MAX_ATTEMPTS = 40;   // × DELAY_SECONDS = ~10 min ceiling
    private const DELAY_SECONDS = 15;
    private const VIDEO_MIME = 'video/mp4';
    private const MS_PER_SECOND = 1000;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue((string) config('trayon.queues.media'));
    }

    public function handle(VideoProviderRouter $router, MediaStorage $media): void
    {
        $run = PlaygroundRun::find($this->runId);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        if ($run->provider_task_id === null || $run->provider_task_id === '') {
            $this->markFailed($run, 'Video task id missing.');

            return;
        }

        $baseUrl = is_array($run->meta) ? ($run->meta[PlaygroundRun::META_BASE_URL] ?? null) : null;
        // Resolve the SAME upstream client that submitted this task (the run's provider).
        $video = $router->for($run->provider);

        try {
            $task = $video->pollTask($run->provider_task_id, $baseUrl);
        } catch (Throwable $e) {
            // A transient poll error counts toward the ceiling, then reschedules.
            $this->reschedule($run, $e->getMessage());

            return;
        }

        if ($video->succeeded($task)) {
            $this->finish($run, $task, $video, $media);

            return;
        }

        if (in_array((string) ($task['status'] ?? ''), VideoGenerationProvider::TERMINAL_FAILURE, true)) {
            $this->markFailed($run, $this->taskError($task));

            return;
        }

        $this->reschedule($run, null); // queued / running / non-terminal
    }

    /** Download + store the MP4 and record duration + cost. */
    private function finish(PlaygroundRun $run, array $task, VideoGenerationProvider $video, MediaStorage $media): void
    {
        $url = data_get($task, 'content.video_url');
        if (! is_string($url) || $url === '') {
            $this->markFailed($run, 'Succeeded task carried no video url.');

            return;
        }

        $bytes = $video->downloadVideo($url);
        if ($bytes === null) {
            // The task DID succeed — a null here is a transient CDN blip. Re-poll (bounded) rather
            // than throwing away a good, already-generated video on one bad download.
            $this->reschedule($run, 'Could not download the result video (will retry).');

            return;
        }

        $stored = $media->storePlaygroundResult($run->id, $bytes, self::VIDEO_MIME);

        [$cost, $source] = $this->cost($run);
        $tokens = (int) (data_get($task, 'usage.total_tokens') ?? 0);

        $run->update([
            'status' => PlaygroundRun::STATUS_SUCCEEDED,
            'result_path' => $stored->path,
            'result_mime' => self::VIDEO_MIME,
            'duration_ms' => $this->renderMs($task),
            'tokens_used' => $tokens > 0 ? $tokens : null,
            'cost_micro_usd' => $cost,
            'cost_source' => $source,
        ]);
    }

    /** Render time = the task's created->updated span (BytePlus-side processing), in ms. */
    private function renderMs(array $task): ?int
    {
        $created = (int) ($task['created_at'] ?? 0);
        $updated = (int) ($task['updated_at'] ?? 0);

        return ($created > 0 && $updated >= $created) ? ($updated - $created) * self::MS_PER_SECOND : null;
    }

    /**
     * Video has no inline USD cost, so the displayed cost is the admin per-video flat-rate price.
     *
     * @return array{0:?int,1:string}
     */
    private function cost(PlaygroundRun $run): array
    {
        $hint = $run->price_hint_micro_usd;

        return ($hint !== null && $hint > 0)
            ? [$hint, PlaygroundRun::COST_SOURCE_FLAT_RATE]
            : [null, PlaygroundRun::COST_SOURCE_UNAVAILABLE];
    }

    /** Count this attempt; fail on the ceiling, else re-dispatch delayed. $error is the last poll error, if any. */
    private function reschedule(PlaygroundRun $run, ?string $error): void
    {
        $attempts = $run->poll_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->markFailed($run, $error ?? 'Video generation timed out (still not ready after '.self::MAX_ATTEMPTS.' checks).');

            return;
        }

        $run->update(['poll_attempts' => $attempts]);

        self::dispatch($run->id)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    private function taskError(array $task): string
    {
        $message = data_get($task, 'error.message');

        return is_string($message) && $message !== '' ? $message : 'Video generation failed.';
    }

    private function markFailed(PlaygroundRun $run, string $message): void
    {
        $run->update(['status' => PlaygroundRun::STATUS_FAILED, 'error' => $message]);
    }

    public function failed(Throwable $e): void
    {
        $run = PlaygroundRun::find($this->runId);

        if ($run !== null && ! $run->isTerminal()) {
            $run->update(['status' => PlaygroundRun::STATUS_FAILED, 'error' => $e->getMessage()]);
        }
    }
}
