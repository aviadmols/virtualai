<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * StoryboardProject — one AI pre-production project (a story idea → a storyboard).
 *
 * GLOBAL (admin-owned, NOT tenant-scoped — on GlobalModels::ALLOW_LIST) and NOT on the money path.
 * Holds the brief (idea + genre + duration + frame interval + aspect/resolution) and the pipeline's
 * intermediate outputs (clean story, genre profile, characters, visual bible) under `pipeline`.
 */
class StoryboardProject extends Model
{
    /** @use HasFactory<\Database\Factories\StoryboardProjectFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    // Keys under `pipeline` (each text step writes its structured output here).
    public const PIPE_STORY = 'story';
    public const PIPE_GENRE = 'genre';
    public const PIPE_CHARACTERS = 'characters';
    public const PIPE_VISUAL_BIBLE = 'visual_bible';

    protected $fillable = [
        'created_by',
        'title',
        'story_idea',
        'genre',
        'duration_seconds',
        'frame_interval_seconds',
        'aspect_ratio',
        'resolution',
        'platform_target',
        'visual_style',
        'status',
        'pipeline',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'pipeline' => 'array',
            'meta' => 'array',
            'duration_seconds' => 'integer',
            'frame_interval_seconds' => 'integer',
        ];
    }

    /** How many frames this project's duration + interval imply (ceil covers a partial last frame). */
    public function expectedFrameCount(): int
    {
        $interval = max(1, (int) $this->frame_interval_seconds);

        return (int) max(1, (int) ceil($this->duration_seconds / $interval));
    }

    /** @return HasMany<StoryboardAsset> */
    public function assets(): HasMany
    {
        return $this->hasMany(StoryboardAsset::class, 'project_id');
    }

    /** @return HasMany<StoryboardFrame> */
    public function frames(): HasMany
    {
        return $this->hasMany(StoryboardFrame::class, 'project_id')->orderBy('frame_number');
    }

    /** @return HasMany<StoryboardStepRun> */
    public function stepRuns(): HasMany
    {
        return $this->hasMany(StoryboardStepRun::class, 'project_id');
    }
}
