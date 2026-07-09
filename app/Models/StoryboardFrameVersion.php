<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StoryboardFrameVersion — one generated/edited image variant of a frame.
 *
 * A frame keeps its history (original, edits, variations); exactly one version is `is_selected`
 * and becomes the frame's shown image. GLOBAL/admin (via the parent frame → project).
 */
class StoryboardFrameVersion extends Model
{
    /** @use HasFactory<\Database\Factories\StoryboardFrameVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'frame_id',
        'image_path',
        'prompt',
        'negative_prompt',
        'reference_assets',
        'edit_instruction',
        'provider',
        'model',
        'version_number',
        'is_selected',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'reference_assets' => 'array',
            'meta' => 'array',
            'version_number' => 'integer',
            'is_selected' => 'boolean',
        ];
    }

    /** @return BelongsTo<StoryboardFrame, StoryboardFrameVersion> */
    public function frame(): BelongsTo
    {
        return $this->belongsTo(StoryboardFrame::class, 'frame_id');
    }
}
