<?php

namespace App\Jobs;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\ImagePayload;
use App\Domain\Media\MediaStorage;
use App\Domain\Playground\PlaygroundImageRunner;
use App\Models\StylePreset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GenerateStylePresetSampleJob — render the super-admin's PREVIEW of a style preset, reusing the
 * un-templated PlaygroundImageRunner.
 *
 * GLOBAL, no tenant, NEVER charges (a sample is not a customer generation — no credit ledger).
 * Resolves the base operation's provider/model/aspect via AiOperationResolver; the prompt is the
 * preset's own, with product {{tokens}} stripped (a sample has no product). The uploaded reference
 * image, if any, is the input. One attempt (no double provider spend). Terminal states: ready|failed.
 */
final class GenerateStylePresetSampleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public int $tries = 1;

    public int $timeout = 90;

    private const LOG_FAILED = 'style_preset.sample_failed';

    public function __construct(
        public readonly int $presetId,
    ) {
        $this->onQueue((string) config('trayon.queues.generations'));
    }

    public function handle(AiOperationResolver $resolver, PlaygroundImageRunner $runner, MediaStorage $media): void
    {
        $preset = StylePreset::find($this->presetId);

        if ($preset === null) {
            return;
        }

        try {
            $config = $resolver->for($preset->operation_key, null, null);

            $inputs = [];
            if ($preset->reference_image_path !== null) {
                $url = $media->signedUrl($preset->reference_image_path);
                if ($url !== null) {
                    $inputs[] = ImagePayload::fromUrl($url);
                }
            }

            $result = $runner->run(
                $config->provider,
                $config->model,
                self::samplePrompt((string) $preset->user_prompt),
                $inputs,
                $config->flatRatePriceMicroUsd(),
                $config->aspectRatio,
            );

            $stored = $media->storeStylePresetSample($preset->id, $result->imageBytes, $result->mimeType);

            $preset->forceFill([
                'sample_image_path' => $stored->path,
                'sample_status' => StylePreset::SAMPLE_READY,
            ])->save();
        } catch (Throwable $e) {
            Log::warning(self::LOG_FAILED, ['preset_id' => $this->presetId, 'error' => $e->getMessage()]);
            $preset->forceFill(['sample_status' => StylePreset::SAMPLE_FAILED])->save();
        }
    }

    /** A sample has no product, so strip the product {{tokens}} — they would render literally. */
    private static function samplePrompt(string $prompt): string
    {
        return trim((string) preg_replace('/\s*\{\{[^}]+\}\}\s*/', ' ', $prompt));
    }
}
