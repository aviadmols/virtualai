<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StoryboardAsset — one tagged reference image for a project (e.g. @main_character, @location_pool).
 *
 * The tag is what the user references inside the story prompt; the pipeline binds the tag to this
 * uploaded image. GLOBAL (admin-owned via the parent project; on GlobalModels::ALLOW_LIST).
 */
class StoryboardAsset extends Model
{
    /** @use HasFactory<\Database\Factories\StoryboardAssetFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const TYPE_CHARACTER = 'character';
    public const TYPE_LOCATION = 'location';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_LOGO = 'logo';
    public const TYPE_STYLE = 'style';
    public const TYPE_OUTFIT = 'outfit';
    public const TYPE_PROP = 'prop';
    public const TYPES = [
        self::TYPE_CHARACTER,
        self::TYPE_LOCATION,
        self::TYPE_PRODUCT,
        self::TYPE_LOGO,
        self::TYPE_STYLE,
        self::TYPE_OUTFIT,
        self::TYPE_PROP,
    ];

    protected $fillable = [
        'project_id',
        'tag',
        'type',
        'file_path',
        'description',
        'reference_strength',
        'keep_exact',
        'is_locked',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'reference_strength' => 'integer',
            'keep_exact' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /** @return BelongsTo<StoryboardProject, StoryboardAsset> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(StoryboardProject::class, 'project_id');
    }
}
