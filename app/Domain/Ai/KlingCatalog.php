<?php

namespace App\Domain\Ai;

/**
 * KlingCatalog — Kling's capability map: which ENDPOINT a model id runs on, and the model-id
 * SUGGESTIONS the admin pickers offer.
 *
 * Kling exposes NO public model-list endpoint (unlike fal), so the lists here are curated
 * SUGGESTIONS, not a source of truth: every picker keeps the id free-text/searchable so a model
 * released tomorrow works today. Kling's model ids are also volatile — a suggestion that 404s is
 * a stale suggestion, never a reason to hardcode a fabricated id (the Seedream scar).
 *
 * Endpoint routing is derived from the id, because Kling splits capabilities across paths:
 *   kolors-virtual-try-on*  -> /v1/images/kolors-virtual-try-on   (human_image + cloth_image)
 *   anything else, images   -> /v1/images/generations
 *   video, with an input frame -> /v1/videos/image2video
 *   video, prompt only         -> /v1/videos/text2video
 */
final class KlingCatalog
{
    // === CONSTANTS ===
    // Endpoint paths (the task is submitted here; the same path + /{task_id} queries it).
    public const PATH_IMAGE = '/v1/images/generations';

    public const PATH_TRY_ON = '/v1/images/kolors-virtual-try-on';

    public const PATH_TEXT_TO_VIDEO = '/v1/videos/text2video';

    public const PATH_IMAGE_TO_VIDEO = '/v1/videos/image2video';

    // A model id starting with this prefix is a virtual-try-on model (its own endpoint + fields).
    public const TRY_ON_PREFIX = 'kolors-virtual-try-on';

    // The model-id enums are PER ENDPOINT — an id valid on one Kling endpoint 404s on another
    // (code 1203, "the model does not exist"). Keeping one merged list is exactly the mistake that
    // 404'd the fabricated Seedream id in production, so the lists stay split, per the docs.

    // IMAGE ids (/v1/images/generations). NOTE: kling-v2-5-turbo is VIDEO-ONLY — it is not here.
    public const IMAGE_MODELS = [
        'kling-v3',
        'kling-v2-1',
        'kling-v2-new',
        'kling-v2',
        'kling-v1-5',
        'kling-v1',
    ];

    // VIRTUAL TRY-ON ids (the dedicated kolors-virtual-try-on endpoint). There is no v2.
    public const TRY_ON_MODELS = [
        'kolors-virtual-try-on-v1-5',
        'kolors-virtual-try-on-v1',
    ];

    // TEXT-TO-VIDEO ids (/v1/videos/text2video).
    public const TEXT_TO_VIDEO_MODELS = [
        'kling-v3',
        'kling-v2-6',
        'kling-v2-5-turbo',
        'kling-v2-1-master',
        'kling-v2-master',
        'kling-v1-6',
        'kling-v1',
    ];

    // IMAGE-TO-VIDEO ids: the text-to-video line PLUS two that are image-to-video only.
    public const IMAGE_TO_VIDEO_ONLY_MODELS = [
        'kling-v2-1',
        'kling-v1-5',
    ];

    // Every id valid on EITHER video endpoint. A picker cannot know which endpoint the run will
    // take (that depends on whether an input frame is present), so it offers the union.
    public const VIDEO_MODELS = [...self::TEXT_TO_VIDEO_MODELS, ...self::IMAGE_TO_VIDEO_ONLY_MODELS];

    // Ids that belong to a DIFFERENT Kling API surface (its own request envelope, its own task
    // routes and status vocabulary). This client speaks only /v1/* — never catalogue these.
    public const UNSUPPORTED_SURFACE_MODELS = [
        'kling-3.0-turbo',
    ];

    // Kling hard-caps prompt AND negative_prompt at 2500 chars — one char over is an
    // HTTP 400 / code 1201 ("prompt: size must be between 0 and 2500") and the task never
    // submits. An admin-edited template joined with real product facts can legitimately
    // overflow, so the boundary clamps instead of letting a paying generation die.
    public const PROMPT_MAX_CHARS = 2500;

    /** True when this model id runs on the dedicated virtual-try-on endpoint. */
    public static function isTryOn(string $modelId): bool
    {
        return str_starts_with(trim($modelId), self::TRY_ON_PREFIX);
    }

    /** A prompt Kling accepts: over-limit text is cut at the last word inside the cap. */
    public static function clampPrompt(string $prompt): string
    {
        if (mb_strlen($prompt) <= self::PROMPT_MAX_CHARS) {
            return $prompt;
        }

        $cut = mb_substr($prompt, 0, self::PROMPT_MAX_CHARS);
        $space = mb_strrpos($cut, ' ');

        // Cut on the last word boundary unless that loses a big tail of the budget.
        return rtrim($space !== false && $space > self::PROMPT_MAX_CHARS - 200
            ? mb_substr($cut, 0, $space)
            : $cut);
    }

    /** The IMAGE endpoint a model id submits to (try-on models have their own path). */
    public static function imagePath(string $modelId): string
    {
        return self::isTryOn($modelId) ? self::PATH_TRY_ON : self::PATH_IMAGE;
    }

    /** The VIDEO endpoint: an input frame means image-to-video, otherwise text-to-video. */
    public static function videoPath(bool $hasInputImage): string
    {
        return $hasInputImage ? self::PATH_IMAGE_TO_VIDEO : self::PATH_TEXT_TO_VIDEO;
    }

    /** True when the id belongs to a Kling API surface this client does not speak. */
    public static function isUnsupported(string $modelId): bool
    {
        return in_array(trim($modelId), self::UNSUPPORTED_SURFACE_MODELS, true);
    }

    /**
     * Image-picker suggestions: the image line + the try-on models (both run through the image
     * client). @return array<int,string>
     */
    public static function imageSuggestions(): array
    {
        return [...self::TRY_ON_MODELS, ...self::IMAGE_MODELS];
    }

    /** Every id valid on EITHER video endpoint (the picker cannot know which the step will use). */
    public static function videoSuggestions(): array
    {
        return self::VIDEO_MODELS;
    }

    /** Select-ready options (`id => id`) for a Filament Select. @return array<string,string> */
    public static function options(bool $video = false): array
    {
        $ids = $video ? self::videoSuggestions() : self::imageSuggestions();

        return array_combine($ids, $ids);
    }
}
