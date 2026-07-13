<?php

namespace App\Models;

use Database\Factories\ShopifyWebhookReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * ShopifyWebhookReceipt — one inbound Shopify webhook delivery, as a DURABLE row.
 *
 * PLATFORM-level (GlobalModels::ALLOW_LIST): the row is created PRE-BIND, before the
 * owning tenant is known — the same documented exception class as the SiteRouter
 * lookup. `webhook_id` (Shopify's X-Shopify-Webhook-Id) is the dedupe wall: a
 * replayed delivery processes at most once.
 *
 * The state machine makes THIS ROW — not the queue — the source of truth for "was
 * this webhook handled":
 *
 *   received   -> queued | failed        (failed = no handler / unknown shop)
 *   queued     -> processing | queued | failed   (queued->queued = recovery re-dispatch)
 *   processing -> processed | failed
 *   failed     -> queued                 (manual/recovery replay)
 *   processed  is terminal.
 *
 * Returning 200 to Shopify and then losing the dispatched job is recoverable: the
 * recovery sweep re-dispatches anything stuck in received/queued (bounded attempts).
 */
class ShopifyWebhookReceipt extends Model
{
    /** @use HasFactory<ShopifyWebhookReceiptFactory> */
    use HasFactory;

    // === CONSTANTS ===
    public const STATUS_RECEIVED = 'received';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const TRANSITIONS = [
        self::STATUS_RECEIVED => [self::STATUS_QUEUED, self::STATUS_FAILED],
        self::STATUS_QUEUED => [self::STATUS_PROCESSING, self::STATUS_QUEUED, self::STATUS_FAILED],
        self::STATUS_PROCESSING => [self::STATUS_PROCESSED, self::STATUS_FAILED],
        self::STATUS_PROCESSED => [],
        self::STATUS_FAILED => [self::STATUS_QUEUED],
    ];

    // The states the recovery sweep treats as "possibly lost in transit".
    public const STATUSES_STUCK = [self::STATUS_RECEIVED, self::STATUS_QUEUED];

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal webhook-receipt status transition %s -> %s (receipt #%s).';

    protected $fillable = [
        'webhook_id',
        'topic',
        'shop_domain',
        'status',
        'payload',
        'attempts',
        'last_error',
        'correlation_id',
        'processed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_RECEIVED,
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Guarded status move. Only canonical transitions are legal; anything else throws.
     * `queued -> queued` is the recovery re-dispatch (attempts already bumped by the
     * dispatcher). No activity event here — receipts are pre-bind infrastructure; the
     * tenant-facing trace is written by the topic handler once the tenant is bound.
     */
    public function transitionTo(string $next, ?string $error = null): void
    {
        $current = $this->status ?? self::STATUS_RECEIVED;

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

        if ($next === self::STATUS_PROCESSED) {
            $this->processed_at = now();
        }

        $this->save();
    }
}
