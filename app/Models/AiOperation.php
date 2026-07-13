<?php

namespace App\Models;

use Database\Factories\AiOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AiOperation — per-operation defaults (GLOBAL control plane).
 *
 * On GlobalModels::ALLOW_LIST: a platform catalog, NOT tenant-scoped. Holds the
 * default/fallback model, image quality, aspect ratio, sampler params, retention,
 * estimated cost and the per-operation credit_multiplier. The resolver reads it;
 * Super-Admin edits it from the DB without a redeploy.
 */
class AiOperation extends Model
{
    /** @use HasFactory<AiOperationFactory> */
    use HasFactory;

    // === CONSTANTS ===
    // The operation keys the platform runs. Callers reference these consts,
    // never a magic string.
    public const KEY_PRODUCT_SCAN = 'product_scan';

    public const KEY_TRY_ON_GENERATION = 'try_on_generation';

    public const KEY_BANNER_GENERATION = 'banner_generation';

    // Product Image Studio (bulk merchant-billed transforms of a product's OWN photos).
    // Two INDEPENDENT operations — each with its own model / prompt / credit multiplier, so
    // an admin can price and tune them separately without touching code.
    //   packshot_generation  — a model-worn (or busy) photo -> a clean e-commerce packshot.
    //   on_model_generation  — a packshot -> the product worn/used by a model.
    public const KEY_PACKSHOT_GENERATION = 'packshot_generation';

    public const KEY_ON_MODEL_GENERATION = 'on_model_generation';

    // The operations the Product Image Studio offers (the merchant's operation picker).
    public const PRODUCT_IMAGE_KEYS = [
        self::KEY_PACKSHOT_GENERATION,
        self::KEY_ON_MODEL_GENERATION,
    ];

    // Storyboard pipeline steps — each pre-production step is a DB-managed operation
    // (its own model/prompt/params/schema/fallback), run in order by StoryboardPipeline.
    // The STORY DIRECTOR is the single planning call: story bible + genre + characters +
    // visual bible + the locked shot timing in ONE structured output (it replaced the four
    // separate read_idea/genre/characters/visual_bible steps — half the cost, no drift).
    public const KEY_STORYBOARD_STORY_DIRECTOR = 'storyboard_story_director';

    public const KEY_STORYBOARD_SCENE_BREAKDOWN = 'storyboard_scene_breakdown';

    public const KEY_STORYBOARD_FRAME_IMAGE = 'storyboard_frame_image';

    public const KEY_STORYBOARD_CLIP = 'storyboard_clip';

    // On-demand (not a pipeline step): AI-rewrite a single frame's image_prompt from an instruction.
    public const KEY_STORYBOARD_IMPROVE_PROMPT = 'storyboard_improve_prompt';

    // On-demand (not a pipeline step): VISION analysis of one reference upload → a ground-truth
    // physical description + subject type, injected into the planning steps for character fidelity.
    public const KEY_STORYBOARD_ASSET_ANALYSIS = 'storyboard_asset_analysis';

    // On-demand (not a pipeline step): the DIRECTOR pass — a multimodal model receives the
    // generated frame images + storyboard data and composes the final one-call video prompt.
    public const KEY_STORYBOARD_VIDEO_DIRECTOR = 'storyboard_video_director';

    public const KEYS = [
        self::KEY_PRODUCT_SCAN,
        self::KEY_TRY_ON_GENERATION,
        self::KEY_BANNER_GENERATION,
        self::KEY_PACKSHOT_GENERATION,
        self::KEY_ON_MODEL_GENERATION,
        self::KEY_STORYBOARD_STORY_DIRECTOR,
        self::KEY_STORYBOARD_SCENE_BREAKDOWN,
        self::KEY_STORYBOARD_FRAME_IMAGE,
        self::KEY_STORYBOARD_CLIP,
        self::KEY_STORYBOARD_IMPROVE_PROMPT,
        self::KEY_STORYBOARD_ASSET_ANALYSIS,
        self::KEY_STORYBOARD_VIDEO_DIRECTOR,
    ];

    protected $fillable = [
        'operation_key',
        'label',
        'default_model',
        'fallback_model',
        'image_quality',
        'aspect_ratio',
        'params',
        'input_schema',
        'retention_days',
        'estimated_cost_micro_usd',
        'credit_multiplier',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'input_schema' => 'array',
            'retention_days' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'credit_multiplier' => 'decimal:3',
        ];
    }

    /** The catalog of allowed models for this operation. */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'operation_key', 'operation_key');
    }
}
