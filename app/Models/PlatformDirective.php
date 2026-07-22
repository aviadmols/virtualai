<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PlatformDirective — the Super-Admin "global rules" for a surface.
 *
 * One row per surface. When active + non-empty, its `rules` text is appended to the SYSTEM prompt
 * of every generation of that surface across ALL sites (AiOperationResolver), and its `version`
 * folds into the generation idempotency keys so a rule edit re-generates instead of colliding.
 *
 * Platform-global (NOT BelongsToAccount) — on GlobalModels::ALLOW_LIST, like Prompt/AiOperation:
 * a fail-closed tenant scope would wrongly hide it. It is edited ONLY by the Super-Admin
 * (GlobalRules page); a merchant can never read or write it.
 */
class PlatformDirective extends Model
{
    // === CONSTANTS ===
    public const SURFACE_IMAGE_STUDIO = 'image_studio';

    public const SURFACE_TRY_ON = 'try_on';

    public const SURFACES = [
        self::SURFACE_IMAGE_STUDIO,
        self::SURFACE_TRY_ON,
    ];

    protected $fillable = [
        'surface',
        'rules',
        'version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The ACTIVE directive for a surface, or null when there is no row, it is inactive, or its
     * rules are empty — i.e. a no-op. Fail-safe: a missing row simply means "no directive".
     */
    public static function activeFor(string $surface): ?self
    {
        $row = static::query()->where('surface', $surface)->first();

        return $row !== null && $row->is_active && trim((string) $row->rules) !== '' ? $row : null;
    }

    /** The active directive's version for a surface (0 = none) — folded into the idempotency keys. */
    public static function activeVersionFor(string $surface): int
    {
        return self::activeFor($surface)?->version ?? 0;
    }
}
