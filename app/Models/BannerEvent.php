<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BannerEvent — one append-only analytics row for a banner (impression | click).
 *
 * Tenant-owned (BelongsToAccount) + site-scoped. Kept OUT of the shared activity_events
 * funnel: impressions are high-frequency, so a dedicated banner-indexed table gives exact
 * per-banner click counts + impressions -> CTR without bloating the timeline. Append-only
 * (created_at only). anon_token is stored ONLY for client-side per-session impression
 * de-dupe; no other PII.
 */
class BannerEvent extends Model
{
    use BelongsToAccount;

    // === CONSTANTS ===
    // created_at only — append-only, no updated_at column.
    public $timestamps = false;

    // The two event kinds.
    public const KIND_IMPRESSION = 'impression';
    public const KIND_CLICK = 'click';

    public const KINDS = [
        self::KIND_IMPRESSION,
        self::KIND_CLICK,
    ];

    // account_id is stamped by BelongsToAccount; the rest are set by the recorder.
    protected $fillable = [
        'site_id',
        'banner_id',
        'kind',
        'path',
        'anon_token',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'banner_id' => 'integer',
            'created_at' => 'datetime',
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

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Banner::class);
    }
}
