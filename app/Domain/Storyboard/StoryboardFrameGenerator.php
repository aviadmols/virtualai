<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\ImagePayload;
use App\Domain\Ai\AiOperationResolver;
use App\Domain\Credits\CreditMath;
use App\Domain\Media\MediaStorage;
use App\Domain\Playground\PlaygroundImageRunner;
use App\Models\AiOperation;
use App\Models\StoryboardFrame;
use App\Models\StoryboardFrameVersion;
use Throwable;

/**
 * StoryboardFrameGenerator — generates (and re-generates) the image for one storyboard frame.
 *
 * Uses the frame-image AiOperation (admin-configured model/prompt) + the shared image runner, with
 * the frame's own image_prompt + negative + the referenced asset images. Every generation records a
 * new frame VERSION and marks it selected (the frame keeps its history); a locked frame is never
 * touched. NOT a money path — no charge. Failures land the frame in a terminal FAILED state.
 */
final class StoryboardFrameGenerator
{
    // === CONSTANTS ===
    private const MAX_REFERENCES = 4;

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly PlaygroundImageRunner $runner,
        private readonly MediaStorage $media,
    ) {}

    /** Generate a fresh image version (initial generate OR a regenerate/edit). */
    public function generate(StoryboardFrame $frame, ?string $editInstruction = null): void
    {
        if ($frame->is_locked) {
            return;
        }

        $frame->update(['status' => StoryboardFrame::STATUS_GENERATING]);

        $config = $this->resolver->for(AiOperation::KEY_STORYBOARD_FRAME_IMAGE);
        $prompt = $this->buildPrompt($frame, $editInstruction);
        $inputs = $this->referenceImages($frame);

        try {
            $result = $this->runner->run($config->provider, $config->model, $prompt, $inputs, $config->estimatedCostMicroUsd);
        } catch (Throwable $e) {
            $frame->update([
                'status' => StoryboardFrame::STATUS_FAILED,
                'meta' => array_merge($frame->meta ?? [], ['error' => $e->getMessage()]),
            ]);

            return;
        }

        $stored = $this->media->storeStoryboardFrame($frame->project_id, $frame->id, $result->imageBytes, $result->mimeType);

        $this->recordVersion($frame, $stored->path, $prompt, $editInstruction, $config->provider, $result->modelUsed);

        $cost = $result->cost->available && $result->cost->costUsd !== null
            ? CreditMath::usdToMicro($result->cost->costUsd)
            : $config->flatRatePriceMicroUsd();

        $frame->update([
            'image_path' => $stored->path,
            'status' => StoryboardFrame::STATUS_READY,
            'image_cost_micro_usd' => $cost,
        ]);
    }

    /** Make a previously generated version the frame's shown image. */
    public function selectVersion(StoryboardFrame $frame, StoryboardFrameVersion $version): void
    {
        if ((int) $version->frame_id !== (int) $frame->getKey()) {
            return;
        }

        // Query-builder writes (not $version->update) so a stale in-memory is_selected can't
        // make the re-select a silent no-op.
        $frame->versions()->update(['is_selected' => false]);
        $frame->versions()->whereKey($version->getKey())->update(['is_selected' => true]);
        $frame->update(['image_path' => $version->image_path]);
    }

    private function recordVersion(StoryboardFrame $frame, string $path, string $prompt, ?string $edit, string $provider, string $model): StoryboardFrameVersion
    {
        $next = (int) $frame->versions()->max('version_number') + 1;
        $frame->versions()->where('is_selected', true)->update(['is_selected' => false]);

        return $frame->versions()->create([
            'image_path' => $path,
            'prompt' => $prompt,
            'negative_prompt' => $frame->negative_prompt,
            'reference_assets' => $frame->reference_tags,
            'edit_instruction' => $edit,
            'provider' => $provider,
            'model' => $model,
            'version_number' => $next,
            'is_selected' => true,
        ]);
    }

    private function buildPrompt(StoryboardFrame $frame, ?string $edit): string
    {
        $prompt = (string) $frame->image_prompt;

        if ($edit !== null && $edit !== '') {
            $prompt .= "\n\nEdit: ".$edit;
        }

        if (filled($frame->negative_prompt)) {
            $prompt .= "\n\nAvoid: ".$frame->negative_prompt;
        }

        return $prompt;
    }

    /** @return array<int,ImagePayload> the referenced assets' signed image urls, capped */
    private function referenceImages(StoryboardFrame $frame): array
    {
        $tags = array_map(static fn ($t): string => ltrim((string) $t, '@'), $frame->reference_tags ?? []);

        if ($tags === []) {
            return [];
        }

        $assets = $frame->project->assets()
            ->whereIn('tag', $tags)
            ->whereNotNull('file_path')
            ->limit(self::MAX_REFERENCES)
            ->get();

        $inputs = [];
        foreach ($assets as $asset) {
            // Skip a reference whose file is missing on the disk — otherwise the provider gets a
            // 404 download and the whole generation fails; proceeding without it still produces an
            // image (just without that reference).
            if (! $this->media->exists($asset->file_path)) {
                continue;
            }

            $signed = $this->media->signedUrl($asset->file_path);
            if ($signed !== null) {
                $inputs[] = ImagePayload::fromUrl($signed);
            }
        }

        return $inputs;
    }
}
