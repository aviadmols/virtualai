<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * StylePreset — one visual generation "style" the platform super-admin curates.
 *
 * GLOBAL (NOT tenant-scoped — on GlobalModels::ALLOW_LIST) and NOT part of the money path: a
 * preset only swaps the PROMPT of an existing operation, so the generation still charges through
 * the normal credit gate. A preset carries a base `operation_key` (which sets both the SURFACE it
 * shows in and the model/quality via AiOperationResolver), a `user_prompt`, an uploaded reference
 * image, and an admin-generated SAMPLE. Only an APPROVED + active preset is offered in a slider.
 */
class StylePreset extends Model
{
    // === CONSTANTS ===
    // Curation status — only APPROVED presets reach a merchant/shopper slider.
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    // The generated-sample lifecycle.
    public const SAMPLE_PENDING = 'pending';

    public const SAMPLE_READY = 'ready';

    public const SAMPLE_FAILED = 'failed';

    // The surfaces a preset can appear in. Derived from operation_key (a preset targets ONE
    // operation → ONE surface). "Where it appears" = which operation the admin picks.
    public const SURFACE_IMAGE_STUDIO = 'image_studio';

    public const SURFACE_TRY_ON = 'try_on';

    public const SURFACE_BANNER = 'banner';

    // operation_key → surface. The ONLY operations that support styles.
    public const OPERATION_SURFACE = [
        AiOperation::KEY_PACKSHOT_GENERATION => self::SURFACE_IMAGE_STUDIO,
        AiOperation::KEY_ON_MODEL_GENERATION => self::SURFACE_IMAGE_STUDIO,
        AiOperation::KEY_TRY_ON_GENERATION => self::SURFACE_TRY_ON,
        AiOperation::KEY_BANNER_GENERATION => self::SURFACE_BANNER,
    ];

    // The operation keys a preset may target (the style-supporting operations).
    public const OPERATIONS = [
        AiOperation::KEY_PACKSHOT_GENERATION,
        AiOperation::KEY_ON_MODEL_GENERATION,
        AiOperation::KEY_TRY_ON_GENERATION,
        AiOperation::KEY_BANNER_GENERATION,
    ];

    // The operation keys that feed one surface's slider.
    public const SURFACE_OPERATIONS = [
        self::SURFACE_IMAGE_STUDIO => [AiOperation::KEY_PACKSHOT_GENERATION, AiOperation::KEY_ON_MODEL_GENERATION],
        self::SURFACE_TRY_ON => [AiOperation::KEY_TRY_ON_GENERATION],
        self::SURFACE_BANNER => [AiOperation::KEY_BANNER_GENERATION],
    ];

    protected $fillable = [
        'name',
        'operation_key',
        'user_prompt',
        'reference_image_path',
        'sample_image_path',
        'sample_status',
        'status',
        'is_active',
        'sort',
    ];

    protected $attributes = [
        'sample_status' => self::SAMPLE_PENDING,
        'status' => self::STATUS_DRAFT,
        'is_active' => true,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** The surface this preset appears in (derived from its operation). */
    public function surface(): ?string
    {
        return self::OPERATION_SURFACE[$this->operation_key] ?? null;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * The slider list: APPROVED + active presets for the given operations, ordered. Used by
     * each surface (Image Studio / Try-On / Banners) to build its style slider.
     *
     * @param  Builder<StylePreset>  $query
     * @param  array<int,string>  $operationKeys
     */
    public function scopeApprovedForOperations(Builder $query, array $operationKeys): Builder
    {
        return $query
            ->where('status', self::STATUS_APPROVED)
            ->where('is_active', true)
            ->whereIn('operation_key', $operationKeys)
            ->orderBy('sort')
            ->orderBy('id');
    }
}
