<?php

namespace App\Models;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Generation — one try-on attempt, and the ONLY place a credit is charged.
 *
 * Tenant-owned (BelongsToAccount) + site-scoped. Links the lead (end_user), the
 * product, and the selected variant. The status machine is the ARCHITECTURE.md
 * contract, guarded by transitionTo():
 *
 *   pending    -> processing | cancelled
 *   processing -> succeeded  | failed | cancelled
 *   succeeded / failed / cancelled are terminal.
 *
 * Only canonical moves are legal; anything else throws (fail loud). EVERY accepted
 * move writes an activity_event via ActivityRecorder (which swallows its own errors,
 * so a trace failure never rolls back the money path). The charge row in
 * credit_ledger carries the SAME idempotency_key as this row, so a succeeded
 * generation is charged exactly once.
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    // The status machine states.
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

    // The canonical, guarded transition map (ARCHITECTURE.md). The literal source of
    // the diagram: pending->processing|cancelled; processing->succeeded|failed|cancelled.
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_SUCCEEDED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    // The activity kind written on every accepted transition.
    public const KIND_STATUS_CHANGED = 'generation_status_changed';

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal generation status transition %s -> %s (generation #%s).';

    // Meta keys — the structured snapshot a generation carries (height, attrs, the
    // variant + prompt snapshots, the resolved failure detail, retention bookkeeping).
    public const META_HEIGHT = 'user_height';

    public const META_EXTRA_ATTRS = 'extra_attrs';

    public const META_STYLE_ID = 'style_preset_id';

    public const META_VARIANT_SNAPSHOT = 'variant_snapshot';

    public const META_PROMPT_SNAPSHOT = 'prompt_snapshot';

    public const META_FAILURE_MESSAGE = 'failure_message';

    public const META_OPENROUTER_GENERATION_ID = 'openrouter_generation_id';

    public const META_RETENTION_DAYS = 'retention_days';

    // status / idempotency_key / image paths / cost are set by the pipeline, never
    // from arbitrary request input. account_id is stamped by BelongsToAccount.
    protected $fillable = [
        'site_id',
        'end_user_id',
        'product_id',
        'product_variant_id',
        'status',
        'client_request_id',
        'idempotency_key',
        'source_image_path',
        'result_image_path',
        'model_used',
        'actual_cost_micro_usd',
        'duration_ms',
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
            'end_user_id' => 'integer',
            'product_id' => 'integer',
            'product_variant_id' => 'integer',
            'actual_cost_micro_usd' => 'integer',
            'duration_ms' => 'integer',
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

    public function endUser(): BelongsTo
    {
        return $this->belongsTo(EndUser::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
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
     * Guarded status move. Only canonical transitions are legal; anything else
     * throws so an invalid state can never be persisted. Saves the row, then writes
     * an activity_event for the accepted move. The trace is best-effort
     * (ActivityRecorder swallows its own errors) and never blocks the money path.
     *
     * @param  array<string,mixed>  $details  extra context for the timeline trace
     */
    public function transitionTo(string $next, array $details = []): void
    {
        $current = $this->status ?? self::STATUS_PENDING;

        $allowed = self::TRANSITIONS[$current] ?? [];

        if (! in_array($next, $allowed, true)) {
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
