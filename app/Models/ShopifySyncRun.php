<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ShopifySyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * ShopifySyncRun — one product-import run. Tenant-owned (BelongsToAccount) + site-scoped.
 *
 * The row (not the queue) is the source of truth for a paginated catalog walk: `cursor`
 * is the resume point, so a run interrupted by a throttle / worker restart continues from
 * the exact page it stopped on. The counters are the merchant's live progress.
 *
 * Guarded status machine — pending -> running -> completed | failed. An illegal move
 * throws; terminal states never re-open (a re-import is a NEW run).
 */
class ShopifySyncRun extends Model
{
    /** @use HasFactory<ShopifySyncRunFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    public const MODE_CATALOG = 'catalog';       // walk every product in the store

    public const MODE_SELECTION = 'selection';   // import an explicit GID list

    public const MODE_WEBHOOK = 'webhook';       // a products/update push

    public const MODES = [self::MODE_CATALOG, self::MODE_SELECTION, self::MODE_WEBHOOK];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_RUNNING, self::STATUS_FAILED],
        self::STATUS_RUNNING => [self::STATUS_RUNNING, self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
    ];

    // === TRUNCATION ===
    // Why a walk stopped before it saw the whole catalog. A truncated run is COMPLETED
    // (the pages it read are imported and correct) but it is NOT a completeness statement:
    // the archive-stale sweep must never run for it, because "Shopify did not return this
    // product" is only evidence of deletion when the traversal actually FINISHED.
    public const TRUNCATION_MAX_PAGES = 'max_pages';

    // A SELECTION import carried more GIDs than one run may take (shopify.import.selection_max).
    // The picks past the bound were NOT imported, and the merchant is told so rather than left to
    // discover it — a silent slice is a lie about what was imported.
    public const TRUNCATION_SELECTION_MAX = 'selection_max';

    public const TRUNCATION_REASONS = [self::TRUNCATION_MAX_PAGES, self::TRUNCATION_SELECTION_MAX];

    // The counter columns the pipeline increments (never a magic string at a call site).
    public const COUNTER_TOTAL_SEEN = 'total_seen';

    public const COUNTER_IMPORTED = 'imported';

    public const COUNTER_UPDATED = 'updated';

    public const COUNTER_ARCHIVED = 'archived';

    public const COUNTER_FAILED = 'failed';

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal Shopify sync-run status transition %s -> %s (run #%s).';

    private const UNKNOWN_TRUNCATION_MESSAGE = 'Unknown Shopify sync-run truncation reason "%s" (run #%s).';

    protected $fillable = [
        'site_id',
        'mode',
        'status',
        'cursor',
        'requested_gids',
        'total_seen',
        'imported',
        'updated',
        'archived',
        'failed',
        'pages',
        'truncated',
        'truncated_reason',
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
            'requested_gids' => 'array',
            'total_seen' => 'integer',
            'imported' => 'integer',
            'updated' => 'integer',
            'archived' => 'integer',
            'failed' => 'integer',
            'pages' => 'integer',
            'truncated' => 'boolean',
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

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /** Total products this run has finished with (imported + updated + failed). */
    public function processed(): int
    {
        return (int) $this->imported + (int) $this->updated + (int) $this->failed;
    }

    /** True when the walk stopped short of the whole catalog (never a sweep source). */
    public function isTruncated(): bool
    {
        return (bool) $this->truncated;
    }

    /**
     * Record that this walk was cut short. Called INSTEAD of the archive-stale sweep, so a
     * run can never be both "truncated" and "swept" — the two are mutually exclusive by
     * construction (SyncShopifyCatalogJob).
     */
    public function markTruncated(string $reason): void
    {
        if (! in_array($reason, self::TRUNCATION_REASONS, true)) {
            throw new RuntimeException(sprintf(self::UNKNOWN_TRUNCATION_MESSAGE, $reason, $this->getKey() ?? 'new'));
        }

        $this->truncated = true;
        $this->truncated_reason = $reason;
        $this->save();
    }

    /**
     * Guarded status move. Only canonical transitions are legal; anything else throws.
     * running -> running is the page-to-page self-redispatch (the cursor advanced).
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
    }
}
