<?php

namespace App\Domain\Storyboard;

use App\Models\AiOperation;
use App\Models\StoryboardProject;

/**
 * StoryboardStep — the ordered pipeline definition.
 *
 * Each step is an ai_operations key (so its model/prompt/params/schema/fallback are admin-managed
 * on the existing AI screens — no parallel config table). TEXT_STEPS run in order and each writes
 * its structured output under the project's `pipeline[...]` bag; the final scene-breakdown step
 * materialises the frames. IMAGE_STEP generates the image for each frame (Phase 4).
 */
final class StoryboardStep
{
    // === CONSTANTS ===
    // The text pipeline, in execution order.
    public const TEXT_STEPS = [
        AiOperation::KEY_STORYBOARD_READ_IDEA,
        AiOperation::KEY_STORYBOARD_GENRE,
        AiOperation::KEY_STORYBOARD_CHARACTERS,
        AiOperation::KEY_STORYBOARD_VISUAL_BIBLE,
        AiOperation::KEY_STORYBOARD_SCENE_BREAKDOWN,
    ];

    public const IMAGE_STEP = AiOperation::KEY_STORYBOARD_FRAME_IMAGE;

    public const ALL = [...self::TEXT_STEPS, self::IMAGE_STEP];

    // On-demand TEXT operations (not pipeline steps — they run outside StoryboardPipeline) that
    // still go through the text caller; the settings page's Test button treats them as text.
    public const ON_DEMAND_TEXT = [
        AiOperation::KEY_STORYBOARD_IMPROVE_PROMPT,
        AiOperation::KEY_STORYBOARD_ASSET_ANALYSIS,
        AiOperation::KEY_STORYBOARD_VIDEO_DIRECTOR,
    ];

    // Which project.pipeline key each single-object text step stores its output under. The
    // scene-breakdown step is materialised into storyboard_frames instead, so it has no bag key.
    public const PIPELINE_KEY = [
        AiOperation::KEY_STORYBOARD_READ_IDEA => StoryboardProject::PIPE_STORY,
        AiOperation::KEY_STORYBOARD_GENRE => StoryboardProject::PIPE_GENRE,
        AiOperation::KEY_STORYBOARD_CHARACTERS => StoryboardProject::PIPE_CHARACTERS,
        AiOperation::KEY_STORYBOARD_VISUAL_BIBLE => StoryboardProject::PIPE_VISUAL_BIBLE,
    ];

    public static function isTextStep(string $stepKey): bool
    {
        return in_array($stepKey, self::TEXT_STEPS, true);
    }

    /** Pipeline text steps + the on-demand text operations — everything the TEXT caller serves. */
    public static function isTextLike(string $stepKey): bool
    {
        return self::isTextStep($stepKey) || in_array($stepKey, self::ON_DEMAND_TEXT, true);
    }
}
