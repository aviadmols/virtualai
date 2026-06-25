<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AiModel — the catalog of allowed OpenRouter model ids per operation (GLOBAL).
 *
 * On GlobalModels::ALLOW_LIST: a platform catalog, NOT tenant-scoped. Provides
 * the is_default / is_fallback floor the resolver falls back to, and the
 * allow-list a per-site model override is validated against.
 */
class AiModel extends Model
{
    /** @use HasFactory<\Database\Factories\AiModelFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const UNIT_PER_IMAGE = 'per_image';
    public const UNIT_PER_1K_TOKENS = 'per_1k_tokens';

    protected $fillable = [
        'operation_key',
        'model_id',
        'label',
        'is_default',
        'is_fallback',
        'cost_hint_micro_usd',
        'cost_unit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_fallback' => 'boolean',
            'is_active' => 'boolean',
            'cost_hint_micro_usd' => 'integer',
        ];
    }

    /** @param  Builder<AiModel>  $query */
    public function scopeForOperation(Builder $query, string $operationKey): Builder
    {
        return $query->where('operation_key', $operationKey)->where('is_active', true);
    }
}
