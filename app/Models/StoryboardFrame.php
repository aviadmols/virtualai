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

    // Per-frame video clip status (null = no clip requested yet).
    public const VIDEO_GENERATING = 'generating';
    public const VIDEO_READY = 'ready';
    public const VIDEO_FAILED = 'failed';

    // video_meta flag: this clip will LAND on the next shot's opening frame (Kling image_tail),
    // so the composer must keep it at full rendered length — trimming it to the shot seconds
    // would cut off the very frame that makes the cut seamless. Written by the clip generator,
    // read by the video composer.
    public const META_TAIL_APPLIED = 'tail_applied';

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
        'dialogue',
        'image_prompt',
        'negative_prompt',
        'motion_prompt',
        'image_path',
        'video_path',
        'video_status',
        'video_task_id',
        'video_duration_ms',
        'video_poll_attempts',
        'video_meta',
        'image_cost_micro_usd',
        'video_cost_micro_usd',
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
            'video_meta' => 'array',
            'frame_number' => 'integer',
            'start_second' => 'integer',
            'end_second' => 'integer',
            'video_duration_ms' => 'integer',
            'video_poll_attempts' => 'integer',
            'image_cost_micro_usd' => 'integer',
            'video_cost_micro_usd' => 'integer',
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
