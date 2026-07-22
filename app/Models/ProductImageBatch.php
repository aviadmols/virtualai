<?php

namespace App\Models;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ProductImageBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ProductImageBatch — one merchant-triggered bulk AI image run. Tenant-owned
 * (BelongsToAccount) + site-scoped.
 *
 * The ROW is the progress truth (never the queue): each asset's terminal outcome increments
 * a counter under a row lock, so the merchant's live bar survives a worker restart and two
 * workers finishing at once cannot lose a count.
 *
 * The estimate columns are ADVISORY (the "about $X" shown before the run). They never
 * authorise a charge — the money path is per asset (gate -> reserve -> charge-on-success).
 *
 * Guarded status machine: pending -> running -> completed | failed. Terminal is terminal.
 */
class ProductImageBatch extends Model
{
    /** @use HasFactory<ProductImageBatchFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_RUNNING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
    ];

    // Which product image feeds the transform. MAIN is the product's main_image_url; the ALT
    // picks index into products.images (0-based) — a product without that image is SKIPPED,
    // never silently transformed from the wrong photo.
    public const SOURCE_MAIN = 'main';

    public const SOURCE_ALT_1 = 'alt_1';

    public const SOURCE_ALT_2 = 'alt_2';

    public const SOURCE_ALT_3 = 'alt_3';

    // The RESULT of the asset being fixed (the "fix image" rail): the source is NOT a product
    // photo but the private generated result of source_asset_id, resolved FRESH in the worker.
    // NOT a merchant-offered batch pick — only FixProductImage sets it.
    public const SOURCE_RESULT = 'result';

    // The picks a merchant may choose in Generate (unchanged — the studio offers only these).
    public const SOURCE_PICKS = [
        self::SOURCE_MAIN,
        self::SOURCE_ALT_1,
        self::SOURCE_ALT_2,
        self::SOURCE_ALT_3,
    ];

    // Every VALID stored pick, including the internal fix sentinel (what the entry point validates).
    public const SOURCE_PICKS_ALL = [
        self::SOURCE_MAIN,
        self::SOURCE_ALT_1,
        self::SOURCE_ALT_2,
        self::SOURCE_ALT_3,
        self::SOURCE_RESULT,
    ];

    // The counter columns the pipeline increments (never a magic string at a call site).
    public const COUNTER_SUCCEEDED = 'succeeded';

    public const COUNTER_FAILED = 'failed';

    public const COUNTER_SKIPPED = 'skipped';

    public const COUNTERS = [self::COUNTER_SUCCEEDED, self::COUNTER_FAILED, self::COUNTER_SKIPPED];

    // Activity kinds (the taxonomy lives on ActivityEvent — one source of truth).
    public const KIND_STARTED = ActivityEvent::KIND_PRODUCT_IMAGE_BATCH_STARTED;

    public const KIND_COMPLETED = ActivityEvent::KIND_PRODUCT_IMAGE_BATCH_COMPLETED;

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal product-image-batch status transition %s -> %s (batch #%s).';

    private const UNKNOWN_COUNTER_MESSAGE = 'Unknown product-image-batch counter "%s".';

    protected $fillable = [
        'site_id',
        'operation_key',
        'source_pick',
        'notes',
        'aspect_ratio',
        'image_quality',
        'status',
        'total',
        'succeeded',
        'failed',
        'skipped',
        'estimate_per_asset_micro_usd',
        'estimate_micro_usd',
        'charged_micro_usd',
        'correlation_id',
        'last_error',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'succeeded' => 'integer',
            'failed' => 'integer',
            'skipped' => 'integer',
            'estimate_per_asset_micro_usd' => 'integer',
            'estimate_micro_usd' => 'integer',
            'charged_micro_usd' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function assets(): HasMany
    {
        return $this->hasMany(ProductAsset::class, 'batch_id');
    }

    public function isTerminal(): bool
    {
        return self::TRANSITIONS[$this->status] === [];
    }

    /** How many assets have reached a terminal outcome. */
    public function settled(): int
    {
        return (int) $this->succeeded + (int) $this->failed + (int) $this->skipped;
    }

    /** Progress 0..100 for the live bar (a zero-asset batch is complete by definition). */
    public function progressPercent(): int
    {
        if ((int) $this->total <= 0) {
            return 100;
        }

        return (int) min(100, round($this->settled() / (int) $this->total * 100));
    }

    /**
     * Record ONE asset's terminal outcome: increment its counter (+ the charged total on a
     * success) and close the batch when every asset has settled. Runs in a row-locked
     * transaction, so two workers finishing at the same instant cannot lose a count or both
     * complete the batch.
     */
    public function recordOutcome(string $counter, int $chargedMicroUsd = 0): void
    {
        if (! in_array($counter, self::COUNTERS, true)) {
            throw new RuntimeException(sprintf(self::UNKNOWN_COUNTER_MESSAGE, $counter));
        }

        DB::transaction(function () use ($counter, $chargedMicroUsd): void {
            /** @var self $locked */
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                $counter => (int) $locked->{$counter} + 1,
                'charged_micro_usd' => (int) $locked->charged_micro_usd + max(0, $chargedMicroUsd),
            ])->save();

            if (! $locked->isTerminal() && $locked->settled() >= (int) $locked->total) {
                $locked->transitionTo(self::STATUS_COMPLETED);
            }

            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Guarded status move. Only canonical transitions are legal; anything else throws.
     * Every accepted move stamps the run clock and leaves a best-effort activity trace.
     */
    public function transitionTo(string $next, ?string $error = null): void
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

        if ($error !== null) {
            $this->last_error = $error;
        }

        if ($next === self::STATUS_RUNNING && $this->started_at === null) {
            $this->started_at = now();
        }

        if ($this->isTerminal()) {
            $this->finished_at = now();
        }

        $this->save();

        if ($this->isTerminal()) {
            app(ActivityRecorder::class)->record(
                kind: self::KIND_COMPLETED,
                subject: $this,
                details: [
                    'status' => $next,
                    'total' => (int) $this->total,
                    'succeeded' => (int) $this->succeeded,
                    'failed' => (int) $this->failed,
                    'skipped' => (int) $this->skipped,
                    'charged_micro_usd' => (int) $this->charged_micro_usd,
                ],
                siteId: $this->site_id,
            );
        }
    }
}
