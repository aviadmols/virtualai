<?php

namespace App\Models;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ShopifyMediaSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * ShopifyMediaSnapshot — the ORIGINAL gallery of ONE Shopify product, held by US.
 *
 * Tenant-owned (BelongsToAccount) + site-scoped + product-scoped. ONE row per product: the
 * original state is captured ONCE, before the first DESTRUCTIVE push (a replace, or a reorder
 * that moves the featured image), and never overwritten.
 *
 * It is the only thing that makes Undo honest. Shopify's CDN drops an image's bytes once the
 * media object is deleted, so a snapshot that only recorded media IDs could restore an ORDER
 * but not an IMAGE. Every entry therefore carries `path` — our own opaque copy of the original
 * bytes on the media disk.
 *
 * Guarded machine:
 *   capturing -> captured   (the bytes + the order are ours; a destructive push may proceed)
 *   capturing -> failed     (we could not copy the originals -> the push is REFUSED, fail closed)
 *   failed    -> capturing  (a retry of the capture)
 *
 * `captured` is terminal on purpose: a restore does not change the snapshot's meaning, it only
 * stamps restored_at / restore_count, so Undo stays repeatable and idempotent.
 */
class ShopifyMediaSnapshot extends Model
{
    /** @use HasFactory<ShopifyMediaSnapshotFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    public const STATUS_CAPTURING = 'capturing';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FAILED = 'failed';

    public const TRANSITIONS = [
        self::STATUS_CAPTURING => [self::STATUS_CAPTURED, self::STATUS_FAILED],
        self::STATUS_CAPTURED => [],
        self::STATUS_FAILED => [self::STATUS_CAPTURING],
    ];

    // Keys inside one `media` entry (the ordered original gallery).
    public const ENTRY_MEDIA_ID = 'shopify_media_id';

    public const ENTRY_ALT = 'alt';

    public const ENTRY_POSITION = 'position';

    public const ENTRY_SOURCE_URL = 'source_url';

    public const ENTRY_PATH = 'path';

    public const ENTRY_MIME = 'mime';

    public const ENTRY_BYTES = 'bytes';

    // Set by a restore that had to RE-UPLOAD this original (the old media id is dead).
    public const ENTRY_RESTORED_MEDIA_ID = 'restored_media_id';

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal Shopify-media-snapshot transition %s -> %s (snapshot #%s).';

    protected $fillable = [
        'site_id',
        'product_id',
        'external_id',
        'status',
        'media',
        'failure_message',
        'captured_at',
        'restored_at',
        'restore_count',
    ];

    protected $attributes = [
        'status' => self::STATUS_CAPTURING,
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'media' => 'array',
            'restore_count' => 'integer',
            'captured_at' => 'datetime',
            'restored_at' => 'datetime',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isCaptured(): bool
    {
        return $this->status === self::STATUS_CAPTURED;
    }

    /** The ordered original gallery (position 1 first). @return array<int,array<string,mixed>> */
    public function entries(): array
    {
        $entries = (array) ($this->media ?? []);

        usort($entries, static fn (array $a, array $b): int => (int) ($a[self::ENTRY_POSITION] ?? 0) <=> (int) ($b[self::ENTRY_POSITION] ?? 0));

        return $entries;
    }

    /**
     * Guarded status move. Only canonical transitions are legal; anything else throws, so a
     * half-captured snapshot can never present itself as a licence to destroy live media.
     *
     * @param  array<string,mixed>  $details
     */
    public function transitionTo(string $next, array $details = []): void
    {
        $current = $this->status ?? self::STATUS_CAPTURING;

        if (! in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
            throw new RuntimeException(sprintf(
                self::ILLEGAL_TRANSITION_MESSAGE,
                $current,
                $next,
                $this->getKey() ?? 'new',
            ));
        }

        $this->status = $next;

        if ($next === self::STATUS_CAPTURED) {
            $this->captured_at = now();
        }

        $this->save();

        app(ActivityRecorder::class)->record(
            kind: $next === self::STATUS_CAPTURED
                ? ActivityEvent::KIND_SHOPIFY_MEDIA_SNAPSHOT_CAPTURED
                : ActivityEvent::KIND_SHOPIFY_MEDIA_SNAPSHOT_FAILED,
            subject: $this,
            details: ['from' => $current, 'to' => $next, 'product_id' => (int) $this->product_id] + $details,
            siteId: $this->site_id,
        );
    }

    /** Stamp a completed restore (the snapshot itself is KEPT — undo stays repeatable). */
    public function recordRestore(): void
    {
        $this->forceFill([
            'restored_at' => now(),
            'restore_count' => (int) $this->restore_count + 1,
        ])->save();
    }

    /**
     * Bind an original to the NEW media id it came back as, and persist immediately.
     *
     * This is the "persist the remote id in the same breath as the call that mints it" law, on the
     * restore rail: it is called between createMedia() and the READY poll, so a worker that dies
     * mid-restore resumes with the id instead of re-uploading the same original and leaving the
     * merchant a DUPLICATE in a live gallery — one more on every retry.
     */
    public function rememberRestoredMediaId(string $originalMediaId, string $restoredMediaId): void
    {
        $media = (array) ($this->media ?? []);

        foreach ($media as $index => $entry) {
            if ((string) (((array) $entry)[self::ENTRY_MEDIA_ID] ?? '') === $originalMediaId) {
                $media[$index][self::ENTRY_RESTORED_MEDIA_ID] = $restoredMediaId;
            }
        }

        $this->forceFill(['media' => array_values($media)])->save();
    }
}
