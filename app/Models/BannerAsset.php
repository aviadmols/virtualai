<?php

namespace App\Models;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * BannerAsset — one AI generation attempt for a banner (a "chat" iteration), and — like
 * Generation — a money-path row: it is the ONLY thing a banner-generation credit charges.
 *
 * Tenant-owned (BelongsToAccount) + site-scoped, under a Banner. The guarded status machine
 * mirrors Generation:
 *
 *   pending    -> processing | cancelled
 *   processing -> succeeded  | failed | cancelled
 *   succeeded / failed / cancelled are terminal.
 *
 * The charge row in credit_ledger carries the SAME idempotency_key (and references this row
 * by reference_type='banner_asset'), so a succeeded generation is charged exactly once.
 */
class BannerAsset extends Model
{
    /** @use HasFactory<\Database\Factories\BannerAssetFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    // The status machine states (mirrors Generation).
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_SUCCEEDED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    // The activity kind written on every accepted transition.
    public const KIND_STATUS_CHANGED = 'banner_asset_status_changed';

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal banner-asset status transition %s -> %s (asset #%s).';

    // Meta keys — the structured snapshot an asset carries.
    public const META_PROMPT_SNAPSHOT = 'prompt_snapshot';
    public const META_FAILURE_MESSAGE = 'failure_message';
    public const META_OPENROUTER_GENERATION_ID = 'openrouter_generation_id';
    public const META_RETENTION_DAYS = 'retention_days';

    // status / idempotency_key / image paths / cost are set by the pipeline, never from
    // arbitrary request input. account_id is stamped by BelongsToAccount.
    protected $fillable = [
        'site_id',
        'banner_id',
        'status',
        'client_request_id',
        'idempotency_key',
        'brief',
        'source_image_path',
        'image_path',
        'image_mime',
        'image_width',
        'image_height',
        'model_used',
        'actual_cost_micro_usd',
        'charge_ledger_id',
        'failure_code',
        'meta',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'banner_id' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'actual_cost_micro_usd' => 'integer',
            'charge_ledger_id' => 'integer',
            'meta' => 'array',
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

    public function chargeLedger(): BelongsTo
    {
        return $this->belongsTo(CreditLedger::class, 'charge_ledger_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isTerminal(): bool
    {
        return self::TRANSITIONS[$this->status] === [];
    }

    /**
     * Guarded status move (mirrors Generation::transitionTo). Only canonical transitions
     * are legal; anything else throws. Saves the row, then writes a best-effort trace.
     *
     * @param  array<string,mixed>  $details
     */
    public function transitionTo(string $next, array $details = []): void
    {
        $current = $this->status ?? self::STATUS_PENDING;

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
