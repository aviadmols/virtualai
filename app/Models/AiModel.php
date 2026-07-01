<?php

namespace App\Models;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AiModel — the catalog of allowed model ids per operation (GLOBAL), across providers.
 *
 * On GlobalModels::ALLOW_LIST: a platform catalog, NOT tenant-scoped. Provides the
 * is_default / is_fallback floor the resolver falls back to, the allow-list a per-site
 * model override is validated against, and the upstream PROVIDER each model belongs to.
 */
class AiModel extends Model
{
    /** @use HasFactory<\Database\Factories\AiModelFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const UNIT_PER_IMAGE = 'per_image';
    public const UNIT_PER_1K_TOKENS = 'per_1k_tokens';

    public const PROVIDER_OPENROUTER = ImageGenerationProvider::PROVIDER_OPENROUTER;
    public const PROVIDER_BYTEPLUS = ImageGenerationProvider::PROVIDER_BYTEPLUS;

    protected $fillable = [
        'operation_key',
        'provider',
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
