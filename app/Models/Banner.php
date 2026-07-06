<?php

namespace App\Models;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Banner — a merchant-authored, AI-generated promotional banner.
 *
 * Tenant-owned (BelongsToAccount) + site-scoped. Carries the SELECTED generated artwork
 * (public marketing image), the composition (full image vs image + HTML overlay), the
 * click target, the visually-picked host-page placements, and the display rules. The
 * status machine is the guarded contract:
 *
 *   draft   -> active | archived
 *   active  -> paused | archived
 *   paused  -> active | archived
 *   archived is terminal.
 *
 * Only canonical moves are legal (transitionTo throws otherwise); every accepted move
 * writes an activity_event (best-effort — never blocks). Placements/overlay/rules are
 * validated by the Banners domain layer before persistence, never here.
 */
class Banner extends Model
{
    /** @use HasFactory<\Database\Factories\BannerFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    // The status machine states.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ARCHIVED,
    ];

    // The canonical, guarded transition map. Archived is terminal.
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
        self::STATUS_ACTIVE => [self::STATUS_PAUSED, self::STATUS_ARCHIVED],
        self::STATUS_PAUSED => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
    ];

    // The two composition modes.
    public const COMPOSITION_IMAGE = 'image';
    public const COMPOSITION_OVERLAY = 'overlay';

    public const COMPOSITIONS = [
        self::COMPOSITION_IMAGE,
        self::COMPOSITION_OVERLAY,
    ];

    // The activity kind written on every accepted transition.
    public const KIND_STATUS_CHANGED = 'banner_status_changed';

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal banner status transition %s -> %s (banner #%s).';

    // account_id is stamped by BelongsToAccount; image_* / selected_asset_id are set by the
    // pipeline/editor, never from arbitrary request input.
    protected $fillable = [
        'site_id',
        'name',
        'status',
        'composition',
        'selected_asset_id',
        'image_path',
        'image_mime',
        'image_width',
        'image_height',
        'target_url',
        'alt_text',
        'overlay',
        'placements',
        'rules',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'composition' => self::COMPOSITION_IMAGE,
    ];

    protected function casts(): array
    {
        return [
            'selected_asset_id' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'overlay' => 'array',
            'placements' => 'array',
            'rules' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** All generation attempts (candidates) for this banner. */
    public function assets(): HasMany
    {
        return $this->hasMany(BannerAsset::class);
    }

    /** The merchant-chosen artwork asset (app-maintained id; no DB FK). */
    public function selectedAsset(): BelongsTo
    {
        return $this->belongsTo(BannerAsset::class, 'selected_asset_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BannerEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /** True when a selected artwork exists — a banner cannot go live without one. */
    public function hasArtwork(): bool
    {
        return $this->image_path !== null && $this->image_path !== '';
    }

    /**
     * Guarded status move. Only canonical transitions are legal; anything else throws so
     * an invalid state can never be persisted. Saves the row, then writes a best-effort
     * activity_event for the accepted move.
     *
     * @param  array<string,mixed>  $details
     */
    public function transitionTo(string $next, array $details = []): void
    {
        $current = $this->status ?? self::STATUS_DRAFT;

        if (! in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
            throw new RuntimeException(sprintf(
                self::ILLEGAL_TRANSITION_MESSAGE,
                $current,
                $next,
                $this->getKey() ?? 'new',
            ));
        }

        $this->status = $next;
        $this->save();

        app(ActivityRecorder::class)->record(
            kind: self::KIND_STATUS_CHANGED,
            subject: $this,
            details: ['from' => $current, 'to' => $next] + $details,
            siteId: $this->site_id,
        );
    }
}
