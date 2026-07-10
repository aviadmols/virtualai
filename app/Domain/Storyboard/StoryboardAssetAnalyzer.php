<?php

namespace App\Domain\Storyboard;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\StoryboardTextCaller;
use App\Domain\Media\MediaStorage;
use App\Models\AiOperation;
use App\Models\StoryboardAsset;
use App\Models\StoryboardProject;
use Throwable;

/**
 * StoryboardAssetAnalyzer — VISION analysis of one reference upload.
 *
 * A multimodal model looks at the ACTUAL image behind a @tag and writes the ground-truth spec the
 * whole pipeline reuses: a dense physical description (a person's face/hair/build/wardrobe, a
 * product's exact shape/colors/text, a location's dressing/light) + the subject type. The result
 * lands on storyboard_assets.description/type and is injected into the planning prompts as
 * {{reference_descriptions}} — so generated characters actually LOOK like the tagged uploads,
 * even on a text-to-image frame model that never sees the reference.
 *
 * Uses the storyboard_asset_analysis AiOperation (admin-configured model/prompt). NOT a money
 * path — never charges. A failure leaves the asset unanalyzed (the pipeline degrades gracefully).
 */
final class StoryboardAssetAnalyzer
{
    // === CONSTANTS ===
    private const KEY_TYPE = 'subject_type';
    private const KEY_DESCRIPTION = 'description';

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly StoryboardTextCaller $caller,
        private readonly MediaStorage $media,
    ) {}

    /** Analyze one asset's image and store the description (+ corrected type). No-op without a file. */
    public function analyze(StoryboardAsset $asset): void
    {
        if ($asset->file_path === null || ! $this->media->exists($asset->file_path)) {
            return;
        }

        $signed = $this->media->signedUrl($asset->file_path);
        if ($signed === null) {
            return;
        }

        $config = $this->resolver->for(AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS);
        $result = $this->caller->extract($config, [
            'tag' => (string) $asset->tag,
            'declared_type' => (string) $asset->type,
        ], [$signed]);

        $description = trim((string) ($result->json[self::KEY_DESCRIPTION] ?? ''));
        if ($description === '') {
            return;
        }

        $type = (string) ($result->json[self::KEY_TYPE] ?? '');

        $asset->update(array_filter([
            'description' => $description,
            'type' => in_array($type, StoryboardAsset::TYPES, true) ? $type : null,
        ]));
    }

    /**
     * Analyze every asset of a project that still lacks a description — used INLINE by the
     * pipeline before the planning steps, so the descriptions are guaranteed to exist when the
     * prompts are built. Per-asset failures are swallowed (a missing analysis degrades the
     * prompt, it must never fail the pipeline).
     */
    public function analyzeMissing(StoryboardProject $project): void
    {
        $pending = $project->assets()
            ->whereNotNull('file_path')
            ->where(static function ($query): void {
                $query->whereNull('description')->orWhere('description', '');
            })
            ->get();

        foreach ($pending as $asset) {
            try {
                $this->analyze($asset);
            } catch (Throwable) {
                // Degrade gracefully — the pipeline notes "no visual analysis available".
            }
        }
    }
}
