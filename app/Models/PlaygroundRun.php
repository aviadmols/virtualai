<?php

namespace App\Models;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PlaygroundRun — one Super-Admin model-test run (image or video).
 *
 * GLOBAL (NOT tenant-scoped — on GlobalModels::ALLOW_LIST) and NOT part of the money path: a run
 * never touches the credit ledger, never charges. Records the inputs, the result media path, and
 * the measured render time + cost purely for the admin to compare models. Video runs are async
 * (submit -> poll), so a row lives through queued -> running -> succeeded|failed.
 */
class PlaygroundRun extends Model
{
    /** @use HasFactory<\Database\Factories\PlaygroundRunFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KINDS = [self::KIND_IMAGE, self::KIND_VIDEO];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const TERMINAL = [self::STATUS_SUCCEEDED, self::STATUS_FAILED];

    // Where the displayed cost came from (a run never charges — this is honesty, not billing).
    public const COST_SOURCE_INLINE = 'inline';         // real per-request USD (OpenRouter)
    public const COST_SOURCE_FLAT_RATE = 'flat_rate';   // the admin-entered per-run price
    public const COST_SOURCE_UNAVAILABLE = 'unavailable';

    // Providers reuse the canonical ids (image: all; video: byteplus + atlascloud).
    public const PROVIDER_OPENROUTER = ImageGenerationProvider::PROVIDER_OPENROUTER;
    public const PROVIDER_BYTEPLUS = ImageGenerationProvider::PROVIDER_BYTEPLUS;
    public const PROVIDER_XAI = ImageGenerationProvider::PROVIDER_XAI;
    public const PROVIDER_ATLASCLOUD = ImageGenerationProvider::PROVIDER_ATLASCLOUD;
    public const PROVIDER_FAL = ImageGenerationProvider::PROVIDER_FAL;

    // The async VIDEO-capable providers; a video run must use one of these (else it falls back to
    // the BytePlus default so a stale image-provider selection can't mis-route).
    public const VIDEO_PROVIDERS = [self::PROVIDER_BYTEPLUS, self::PROVIDER_ATLASCLOUD, self::PROVIDER_FAL];

    // meta keys — the video request knobs + the resolved region host.
    public const META_RATIO = 'ratio';
    public const META_RESOLUTION = 'resolution';
    public const META_DURATION = 'duration_seconds';
    public const META_BASE_URL = 'base_url';

    protected $fillable = [
        'created_by',
        'kind',
        'provider',
        'model_id',
        'prompt',
        'input_paths',
        'status',
        'provider_task_id',
        'result_path',
        'result_mime',
        'duration_ms',
        'cost_micro_usd',
        'cost_source',
        'price_hint_micro_usd',
        'tokens_used',
        'poll_attempts',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'input_paths' => 'array',
            'meta' => 'array',
            'duration_ms' => 'integer',
            'cost_micro_usd' => 'integer',
            'price_hint_micro_usd' => 'integer',
            'tokens_used' => 'integer',
            'poll_attempts' => 'integer',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }
}
