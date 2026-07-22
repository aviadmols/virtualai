<?php

namespace App\Models;

use Database\Factories\StoryboardProjectFactory;
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
    /** @use HasFactory<StoryboardProjectFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const STATUS_DRAFT = 'draft';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    // Keys under `pipeline`. The Story Director step writes the first five in ONE call
    // (its sections are split into these bags so every downstream reader keeps working);
    // PIPE_TIMING holds the LOCKED per-frame shot timing the scene breakdown must obey.
    public const PIPE_STORY = 'story';

    public const PIPE_GENRE = 'genre';

    public const PIPE_CHARACTERS = 'characters';

    public const PIPE_VISUAL_BIBLE = 'visual_bible';

    public const PIPE_TIMING = 'shot_timing';

    // Story content types (decided by the Story Director from the brief): a complete story
    // must RESOLVE in the final frame; a trailer may end on a deliberate cliffhanger.
    public const CONTENT_COMPLETE = 'complete_micro_story';

    public const CONTENT_TRAILER = 'trailer';

    // Combined-video (all frames stitched into one MP4) lifecycle.
    public const VIDEO_GENERATING = 'generating';

    public const VIDEO_READY = 'ready';

    public const VIDEO_FAILED = 'failed';

    // Shot-based derivation bounds. The Story Director decides the CUT LIST (one shot = one
    // continuous camera setup/movement = one frame); these walls keep its freedom inside cost
    // and pacing limits. frame_interval_seconds is the merchant's PACING HINT, not a slicer.
    // A shot shorter than the shortest clip any video model renders is a lie: the clip would
    // be padded up and the assembled film would overrun its duration. So the plan's floor IS
    // the clip floor (StoryboardClipGenerator::DEFAULT_MIN_CLIP_SECONDS).
    public const MIN_SHOT_SECONDS = 3;

    public const MAX_SHOTS_CAP = 20;

    private const MAX_SHOT_SECONDS_FLOOR = 3;

    private const MAX_SHOT_SECONDS_CEIL = 12;

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
        'final_video_path',
        'final_video_status',
        'final_video_meta',
    ];

    protected function casts(): array
    {
        return [
            'pipeline' => 'array',
            'meta' => 'array',
            'final_video_meta' => 'array',
            'duration_seconds' => 'integer',
            'frame_interval_seconds' => 'integer',
        ];
    }

    /**
     * The longest single shot the director may cut: about twice the pacing hint, so a climax
     * can breathe, clamped to a range that clip models can actually render in one piece.
     */
    public function maxShotSeconds(): int
    {
        $hint = max(1, (int) $this->frame_interval_seconds);

        return (int) min(self::MAX_SHOT_SECONDS_CEIL, max(self::MAX_SHOT_SECONDS_FLOOR, 2 * $hint));
    }

    /** The most shots the director may cut: hard cost cap, never more than the floor fits, never 0. */
    public function maxShotCount(): int
    {
        $duration = max(1, (int) $this->duration_seconds);

        return (int) max(1, min(self::MAX_SHOTS_CAP, intdiv($duration, self::MIN_SHOT_SECONDS)));
    }

    /** The fewest shots that still cover the duration at maxShotSeconds (never above the cap). */
    public function minShotCount(): int
    {
        $duration = max(1, (int) $this->duration_seconds);

        return (int) min($this->maxShotCount(), max(1, (int) ceil($duration / $this->maxShotSeconds())));
    }

    /**
     * The frame count this project will (or did) produce: the LOCKED shot plan's count once
     * the director has run; before that, a pacing-hint estimate for display only.
     */
    public function plannedShotCount(): int
    {
        $timing = $this->pipeline[self::PIPE_TIMING] ?? null;

        if (is_array($timing) && $timing !== []) {
            return count($timing);
        }

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
