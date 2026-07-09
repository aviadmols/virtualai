<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StoryboardStepRun — one execution of one pipeline step for a project (the progress + audit log).
 *
 * Records the step key (an ai_operations operation_key), its input/output, the provider + model
 * that ran, cost + duration, and any error — so the pipeline-progress UI and cost dashboard read
 * from it. GLOBAL/admin (via the parent project). Cost is display-only; a run never charges.
 */
class StoryboardStepRun extends Model
{
    /** @use HasFactory<\Database\Factories\StoryboardStepRunFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id',
        'step_key',
        'status',
        'input',
        'output',
        'provider',
        'model',
        'cost_micro_usd',
        'duration_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'cost_micro_usd' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<StoryboardProject, StoryboardStepRun> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(StoryboardProject::class, 'project_id');
    }
}
