<?php

namespace App\Jobs;

use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\KlingAvatarClient;
use App\Domain\Ai\ParsedCost;
use App\Domain\Ai\VideoProviderRouter;
use App\Domain\Credits\CreditMath;
use App\Domain\Media\MediaStorage;
use App\Domain\Playground\PlaygroundImageRunner;
use App\Models\PlaygroundRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * RunPlaygroundJob — executes ONE admin playground run.
 *
 * NOT tenant-aware and NOT on the money path: no account, no credit ledger, no charge. An IMAGE
 * run happens synchronously here (a single generation fits the 70s generations timeout) and stores
 * the result. A VIDEO run only SUBMITS the async task here and hands off to PollPlaygroundVideoJob
 * — a Seedance task can take minutes, far past any worker timeout, so the poll must be its own
 * short, re-dispatching job.
 */
final class RunPlaygroundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;     // a run never re-executes (no double provider spend)

    public int $timeout = 70;  // GEN_TIMEOUT — image gen fits; video only submits here

    // Seconds before the first video poll (a task is never ready instantly).
    private const VIDEO_FIRST_POLL_DELAY = 15;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue((string) config('trayon.queues.generations'));
    }

    public function handle(PlaygroundImageRunner $images, VideoProviderRouter $router, MediaStorage $media): void
    {
        $run = PlaygroundRun::find($this->runId);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        $run->update(['status' => PlaygroundRun::STATUS_RUNNING]);

        try {
            // Video AND avatar are async (submit here, poll separately); an image runs synchronously.
            $run->producesVideo()
                ? $this->submitVideo($run, $router, $media)
                : $this->runImage($run, $images, $media);
        } catch (Throwable $e) {
            $this->markFailed($run, $e->getMessage());
        }
    }

    /** Synchronous image generation: time it, store the bytes, record the cost. */
    private function runImage(PlaygroundRun $run, PlaygroundImageRunner $images, MediaStorage $media): void
    {
        $inputs = $this->inputPayloads($run, $media);

        $startedAt = hrtime(true);
        $result = $images->run($run->provider, $run->model_id, $run->prompt, $inputs, $run->price_hint_micro_usd);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $stored = $media->storePlaygroundResult($run->id, $result->imageBytes, $result->mimeType);

        [$cost, $source] = $this->costFrom($result->cost);

        $run->update([
            'status' => PlaygroundRun::STATUS_SUCCEEDED,
            'result_path' => $stored->path,
            'result_mime' => $result->mimeType,
            'duration_ms' => $durationMs,
            'cost_micro_usd' => $cost,
            'cost_source' => $source,
        ]);
    }

    /**
     * Submit the async video task, then hand off to the poller (delayed). A normal video run routes
     * through the provider router; an AVATAR run uses the native Kling avatar client directly (kling
     * is the router's video client, not the avatar endpoint) with the signed audio url threaded in.
     */
    private function submitVideo(PlaygroundRun $run, VideoProviderRouter $router, MediaStorage $media): void
    {
        $urls = $this->inputUrls($run, $media);
        $baseUrl = is_array($run->meta) ? ($run->meta[PlaygroundRun::META_BASE_URL] ?? null) : null;
        $params = $run->meta ?? [];

        if ($run->isAvatar()) {
            $client = app(KlingAvatarClient::class);
            $params[KlingAvatarClient::PARAM_AUDIO_URL] = $run->audio_path !== null
                ? $media->signedUrl($run->audio_path)
                : null;
        } else {
            $client = $router->for($run->provider);
        }

        $taskId = $client->submitTask($run->model_id, (string) $run->prompt, $urls, $params, $baseUrl);

        $run->update([
            'provider_task_id' => $taskId,
            'status' => PlaygroundRun::STATUS_RUNNING,
        ]);

        PollPlaygroundVideoJob::dispatch($run->id)->delay(now()->addSeconds(self::VIDEO_FIRST_POLL_DELAY));
    }

    /**
     * Map a ParsedCost to a stored [micro-USD, source]. OpenRouter yields a real inline cost;
     * flat-rate providers yield the admin per-image price; otherwise the cost is unavailable.
     *
     * @return array{0:?int,1:string}
     */
    private function costFrom(ParsedCost $cost): array
    {
        if (! $cost->available || $cost->costUsd === null) {
            return [null, PlaygroundRun::COST_SOURCE_UNAVAILABLE];
        }

        $source = $cost->source === ParsedCost::SOURCE_INLINE
            ? PlaygroundRun::COST_SOURCE_INLINE
            : PlaygroundRun::COST_SOURCE_FLAT_RATE;

        return [CreditMath::usdToMicro($cost->costUsd), $source];
    }

    /** @return array<int,ImagePayload> */
    private function inputPayloads(PlaygroundRun $run, MediaStorage $media): array
    {
        $payloads = [];

        foreach ($this->inputUrls($run, $media) as $url) {
            $payloads[] = ImagePayload::fromUrl($url);
        }

        return $payloads;
    }

    /** @return array<int,string> signed https urls of the stored input images */
    private function inputUrls(PlaygroundRun $run, MediaStorage $media): array
    {
        $urls = [];

        foreach (($run->input_paths ?? []) as $path) {
            $signed = $media->signedUrl((string) $path);
            if ($signed !== null) {
                $urls[] = $signed;
            }
        }

        return $urls;
    }

    private function markFailed(PlaygroundRun $run, string $message): void
    {
        $run->update(['status' => PlaygroundRun::STATUS_FAILED, 'error' => $message]);
    }

    /** Last-resort net: an escaped exception must still land the run in a terminal failed state. */
    public function failed(Throwable $e): void
    {
        $run = PlaygroundRun::find($this->runId);

        if ($run !== null && ! $run->isTerminal()) {
            $run->update(['status' => PlaygroundRun::STATUS_FAILED, 'error' => $e->getMessage()]);
        }
    }
}
