<?php

namespace App\Models;

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
    /** @use HasFactory<\Database\Factories\AiOperationFactory> */
    use HasFactory;

    // === CONSTANTS ===
    // The operation keys the platform runs. Callers reference these consts,
    // never a magic string.
    public const KEY_PRODUCT_SCAN = 'product_scan';
    public const KEY_TRY_ON_GENERATION = 'try_on_generation';
    public const KEY_BANNER_GENERATION = 'banner_generation';

    // Storyboard pipeline steps — each pre-production step is a DB-managed operation
    // (its own model/prompt/params/schema/fallback), run in order by StoryboardPipeline.
    public const KEY_STORYBOARD_READ_IDEA = 'storyboard_read_idea';
    public const KEY_STORYBOARD_GENRE = 'storyboard_genre';
    public const KEY_STORYBOARD_CHARACTERS = 'storyboard_characters';
    public const KEY_STORYBOARD_VISUAL_BIBLE = 'storyboard_visual_bible';
    public const KEY_STORYBOARD_SCENE_BREAKDOWN = 'storyboard_scene_breakdown';
    public const KEY_STORYBOARD_FRAME_IMAGE = 'storyboard_frame_image';

    public const KEYS = [
        self::KEY_PRODUCT_SCAN,
        self::KEY_TRY_ON_GENERATION,
        self::KEY_BANNER_GENERATION,
        self::KEY_STORYBOARD_READ_IDEA,
        self::KEY_STORYBOARD_GENRE,
        self::KEY_STORYBOARD_CHARACTERS,
        self::KEY_STORYBOARD_VISUAL_BIBLE,
        self::KEY_STORYBOARD_SCENE_BREAKDOWN,
        self::KEY_STORYBOARD_FRAME_IMAGE,
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
