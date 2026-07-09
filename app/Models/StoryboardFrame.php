<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * StoryboardFrame — one storyboard frame (one image per ~N seconds of the planned video).
 *
 * Carries the scene breakdown (description, camera, action, referenced tags, on-screen text) + the
 * assembled image_prompt/negative_prompt and the selected image. Each frame is EDITED and
 * regenerated independently; its image history lives in storyboard_frame_versions. GLOBAL/admin.
 */
class StoryboardFrame extends Model
{
    /** @use HasFactory<\Database\Factories\StoryboardFrameFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id',
        'frame_number',
        'start_second',
        'end_second',
        'description',
        'camera_angle',
        'composition',
        'action',
        'characters',
        'reference_tags',
        'text_overlay',
        'image_prompt',
        'negative_prompt',
        'image_path',
        'status',
        'is_approved',
        'is_locked',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'characters' => 'array',
            'reference_tags' => 'array',
            'meta' => 'array',
            'frame_number' => 'integer',
            'start_second' => 'integer',
            'end_second' => 'integer',
            'is_approved' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /** @return BelongsTo<StoryboardProject, StoryboardFrame> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(StoryboardProject::class, 'project_id');
    }

    /** @return HasMany<StoryboardFrameVersion> */
    public function versions(): HasMany
    {
        return $this->hasMany(StoryboardFrameVersion::class, 'frame_id')->orderBy('version_number');
    }
}
