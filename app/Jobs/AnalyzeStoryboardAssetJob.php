<?php

namespace App\Jobs;

use App\Domain\Storyboard\StoryboardAssetAnalyzer;
use App\Models\StoryboardAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AnalyzeStoryboardAssetJob — vision-analyze ONE reference upload off-request (pre-warm).
 *
 * Dispatched when reference images are saved, so the ground-truth descriptions are usually ready
 * before the pipeline runs; the pipeline still analyzes any stragglers inline. One asset per job;
 * tries=1 (an analysis is retried on the next save/run, never silently re-spent). NOT a money path.
 */
final class AnalyzeStoryboardAssetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;
    public int $timeout = 70;

    public function __construct(
        public readonly int $assetId,
    ) {
        $this->onQueue((string) config('trayon.queues.generations'));
    }

    public function handle(StoryboardAssetAnalyzer $analyzer): void
    {
        $asset = StoryboardAsset::find($this->assetId);

        if ($asset === null) {
            return;
        }

        // Pre-warm only — a failure is logged and the pipeline analyzes the straggler inline.
        try {
            $analyzer->analyze($asset);
        } catch (Throwable $e) {
            Log::warning('storyboard.asset_analysis.failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
