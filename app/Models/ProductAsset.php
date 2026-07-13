<?php

namespace App\Models;

use App\Domain\Activity\ActivityRecorder;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ProductAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ProductAsset — ONE AI image transform of ONE product photo (a packshot, or the product on a
 * model). Tenant-owned (BelongsToAccount) + site-scoped + product-scoped, and — like
 * Generation and BannerAsset — a MONEY-PATH row: it is the only thing a product-image credit
 * charges, and the charge row in credit_ledger carries this row's deterministic
 * idempotency_key (reference_type = 'product_asset').
 *
 * TWO independent guarded machines live here, and they never collapse into one:
 *
 *   status (the generation machine — the money path)
 *     pending -> processing -> succeeded | failed | cancelled
 *
 *   review_status (the merchant machine — pure editorial)
 *     awaiting_review -> approved | rejected   (and a merchant may flip approved <-> rejected)
 *
 * A rejection is NOT a refund. The AI ran and the provider billed us, so the charge stands —
 * the UI states this plainly before the merchant starts a batch. Review is therefore only
 * legal once `status === succeeded` (there is nothing to judge before that).
 */
class ProductAsset extends Model
{
    /** @use HasFactory<ProductAssetFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    // --- the generation machine (mirrors Generation / BannerAsset) ---
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

    // The SETTLED statuses (no transition leaves them). A render is in flight until it reaches
    // one of these — which is what makes the regenerate counter (RegenerateProductImage) safe:
    // an in-flight regeneration never advances it, so a repeat click collapses onto its key.
    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    // --- the review machine (merchant judgement on a SUCCEEDED asset) ---
    public const REVIEW_AWAITING = 'awaiting_review';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_STATUSES = [
        self::REVIEW_AWAITING,
        self::REVIEW_APPROVED,
        self::REVIEW_REJECTED,
    ];

    public const REVIEW_TRANSITIONS = [
        self::REVIEW_AWAITING => [self::REVIEW_APPROVED, self::REVIEW_REJECTED],
        self::REVIEW_APPROVED => [self::REVIEW_REJECTED],
        self::REVIEW_REJECTED => [self::REVIEW_APPROVED],
    ];

    // --- the push machine (Phase 5: push an APPROVED image to the store's product media) ---
    // It is INDEPENDENT of the two machines above and never collapses into them: a push is
    // FREE (no reservation, no charge, no ledger row — the AI already ran and was paid for),
    // so a re-push retries the PUSH ONLY and can never re-run a generation.
    public const PUSH_NOT_PUSHED = 'not_pushed';

    public const PUSH_PUSHING = 'pushing';

    public const PUSH_PUSHED = 'pushed';

    public const PUSH_FAILED = 'push_failed';

    public const PUSH_STATUSES = [
        self::PUSH_NOT_PUSHED,
        self::PUSH_PUSHING,
        self::PUSH_PUSHED,
        self::PUSH_FAILED,
    ];

    public const PUSH_TRANSITIONS = [
        self::PUSH_NOT_PUSHED => [self::PUSH_PUSHING],
        // UNDO reaches `pushing` too. Undo takes OUR media back out of the live gallery by the
        // append-only mint record, not by this row's (mutable) pointer — so after an undo an
        // in-flight or lost push is simply no longer in the store, and the row must be able to
        // say so. Leaving it stranded at `pushing` was a dead end: a later re-push would resume a
        // media id that no longer exists.
        self::PUSH_PUSHING => [self::PUSH_PUSHED, self::PUSH_FAILED, self::PUSH_NOT_PUSHED],
        // UNDO: the merchant restored the product's original gallery, so the image we added is
        // gone from Shopify. The asset returns to not_pushed (its bytes are still ours — it can
        // be pushed again, for free).
        self::PUSH_PUSHED => [self::PUSH_NOT_PUSHED],
        // RE-PUSH: retry the PUSH, never the generation.
        self::PUSH_FAILED => [self::PUSH_PUSHING, self::PUSH_NOT_PUSHED],
    ];

    // Where an approved image goes in the product's Shopify gallery.
    public const PLACEMENT_APPEND = 'append';       // end of the gallery — the safe default

    public const PLACEMENT_POSITION = 'position';   // a specific 1-based slot (1 = featured)

    public const PLACEMENT_REPLACE = 'replace';     // swap out a specific existing image

    public const PLACEMENTS = [
        self::PLACEMENT_APPEND,
        self::PLACEMENT_POSITION,
        self::PLACEMENT_REPLACE,
    ];

    // The client_request_id a normal BATCH run carries. It is CONSTANT on purpose: the whole
    // key is then determined by {product, source image, operation, prompt version, model,
    // params}, so re-running the same selection (or double-clicking Run) can neither
    // regenerate nor re-charge the same image. "Regenerate" mints a FRESH id on purpose.
    public const REQUEST_BATCH = 'batch';

    public const REQUEST_REGENERATE_PREFIX = 'regen-';

    // Activity kinds (the taxonomy lives on ActivityEvent — one source of truth).
    public const KIND_STATUS_CHANGED = ActivityEvent::KIND_PRODUCT_ASSET_STATUS_CHANGED;

    public const KIND_APPROVED = ActivityEvent::KIND_PRODUCT_ASSET_APPROVED;

    public const KIND_REJECTED = ActivityEvent::KIND_PRODUCT_ASSET_REJECTED;

    public const KIND_PUSH_STATUS_CHANGED = ActivityEvent::KIND_PRODUCT_ASSET_PUSH_STATUS_CHANGED;

    // meta keys — the structured snapshot an asset carries.
    public const META_PROMPT_SNAPSHOT = 'prompt_snapshot';

    public const META_FAILURE_MESSAGE = 'failure_message';

    public const META_PROVIDER_GENERATION_ID = 'provider_generation_id';

    // provider_meta keys — the async ticket + the price that was locked in at submit time.
    public const PROVIDER_META_TICKET = 'ticket';

    public const PROVIDER_META_FLAT_RATE_MICRO_USD = 'flat_rate_price_micro_usd';

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal product-asset status transition %s -> %s (asset #%s).';

    private const ILLEGAL_REVIEW_MESSAGE = 'Illegal product-asset review transition %s -> %s (asset #%s).';

    private const REVIEW_BEFORE_SUCCESS_MESSAGE = 'Product asset #%s cannot be reviewed: it is %s, not succeeded.';

    private const ILLEGAL_PUSH_MESSAGE = 'Illegal product-asset push transition %s -> %s (asset #%s).';

    private const PUSH_BEFORE_APPROVAL_MESSAGE = 'Product asset #%s cannot be pushed: review is %s, not approved.';

    private const REJECT_WHILE_PUSHED_MESSAGE = 'Product asset #%s is in the store (push status %s) and cannot be rejected; undo the push first.';

    // When a `pushing` asset is considered LOST rather than in flight (a killed worker never
    // calls failed(), and an asset stuck at `pushing` can never be pushed again).
    private const CFG_STUCK_MINUTES = 'shopify.media.stuck_after_minutes';

    private const DEFAULT_STUCK_MINUTES = 30;

    // status / idempotency_key / paths / cost are set by the pipeline, never from request input.
    // account_id is stamped by BelongsToAccount.
    protected $fillable = [
        'site_id',
        'product_id',
        'batch_id',
        'source_asset_id',
        'operation_key',
        'status',
        'review_status',
        'client_request_id',
        'idempotency_key',
        'source_image_url',
        'source_image_hash',
        'image_path',
        'image_mime',
        'image_width',
        'image_height',
        'model_used',
        'provider',
        'provider_request_id',
        'provider_meta',
        'poll_attempts',
        'reserved_micro_usd',
        'actual_cost_micro_usd',
        'charge_micro_usd',
        'charge_ledger_id',
        'failure_code',
        'meta',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'review_status' => self::REVIEW_AWAITING,
        'push_status' => self::PUSH_NOT_PUSHED,
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'batch_id' => 'integer',
            'source_asset_id' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'poll_attempts' => 'integer',
            'reserved_micro_usd' => 'integer',
            'actual_cost_micro_usd' => 'integer',
            'charge_micro_usd' => 'integer',
            'charge_ledger_id' => 'integer',
            'provider_meta' => 'array',
            'meta' => 'array',
            'push_position' => 'integer',
            'pushed_at' => 'datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductImageBatch::class, 'batch_id');
    }

    /** The asset this one was REGENERATED from (null for a normal batch asset). */
    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_asset_id');
    }

    /** Every regeneration minted from THIS asset (the counter behind the regenerate intent). */
    public function regenerations(): HasMany
    {
        return $this->hasMany(self::class, 'source_asset_id');
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

    public function isApproved(): bool
    {
        return $this->review_status === self::REVIEW_APPROVED;
    }

    /** True while the merchant still has to judge this (succeeded) image. */
    public function isAwaitingReview(): bool
    {
        return $this->isSucceeded() && $this->review_status === self::REVIEW_AWAITING;
    }

    public function isPushed(): bool
    {
        return $this->push_status === self::PUSH_PUSHED;
    }

    public function isPushing(): bool
    {
        return $this->push_status === self::PUSH_PUSHING;
    }

    public function isPushFailed(): bool
    {
        return $this->push_status === self::PUSH_FAILED;
    }

    /** In the store, or on its way there — either way the storefront may be showing it. */
    public function isInStore(): bool
    {
        return $this->isPushed() || $this->isPushing();
    }

    /**
     * A push that is LOST, not in flight. A SIGKILL/OOM worker never calls failed(), so the asset
     * would sit at `pushing` forever and the merchant could never push that image again (both push
     * and re-push deny an in-flight asset). Past the stuck window the push is reclaimable.
     *
     * IT JUDGES BY `updated_at`, WHICH IS WHY THE LEASE MUST RE-STAMP IT (see takePushLease). A
     * claim that never touches the field its own freshness is measured by admits a second worker
     * into a push that is very much alive.
     */
    public function isPushStuck(): bool
    {
        if (! $this->isPushing() || $this->updated_at === null) {
            return false;
        }

        return $this->updated_at->lt(now()->subMinutes(self::stuckAfterMinutes()));
    }

    /**
     * TAKE (or RENEW) THE PUSH LEASE. Called ONLY inside the row-locked claim transaction.
     *
     * Both halves of the lease are stamped in one write:
     *   - the claim id: WHO holds the push. Passing null mints a FRESH id, which EVICTS whatever
     *     worker held the old one — it re-checks (holdsPushClaim) before it mints Shopify media
     *     and stands down when its claim is gone.
     *   - updated_at: HOW FRESH the lease is — the field isPushStuck() judges by. Re-stamping it
     *     is what stops a second reclaim from walking in behind the first.
     */
    public function takePushLease(?string $renew = null): string
    {
        $claim = $renew ?? (string) Str::uuid();

        $this->forceFill([
            'push_claim_id' => $claim,
            'updated_at' => now(),
        ])->save();

        return $claim;
    }

    /** Drop the lease — the push is over (succeeded, failed, or undone). */
    public function releasePushLease(): void
    {
        $this->forceFill(['push_claim_id' => null])->save();
    }

    /**
     * Is THIS claim still the one holding the lease? Re-read from the DB on purpose: the in-memory
     * model is a photograph, and the question is about NOW — a reclaim may have evicted us while we
     * were talking to Shopify. Runs under the BelongsToAccount global scope (fail closed).
     */
    public function holdsPushClaim(?string $claimId): bool
    {
        if (! is_string($claimId) || $claimId === '') {
            return false;
        }

        $current = static::query()->whereKey($this->getKey())->value('push_claim_id');

        return is_string($current) && $current === $claimId;
    }

    private static function stuckAfterMinutes(): int
    {
        return max(1, (int) (config(self::CFG_STUCK_MINUTES) ?? self::DEFAULT_STUCK_MINUTES));
    }

    /** The Shopify media this asset became (set the moment productCreateMedia answers). */
    public function hasShopifyMedia(): bool
    {
        return is_string($this->shopify_media_id) && $this->shopify_media_id !== '';
    }

    /**
     * Guarded PUSH move (the store machine). Legal only on an APPROVED image — the merchant's
     * judgement is what unlocks the storefront — and only along the push machine.
     *
     * THE APPROVAL GATE GUARDS THE WAY *INTO* THE STORE, NOT THE WAY OUT. Leaving the storefront
     * (-> not_pushed, which is what UNDO does) needs no approval and never has: an image being
     * REMOVED from a live gallery can always be removed. Demanding approval on the way out was a
     * trap — a merchant who rejected an already-pushed image made undo throw AFTER it had already
     * mutated the store, stranding the asset at `pushed` with a dead media id and an Undo button
     * that threw forever.
     *
     * A push consumes NO credit: pushing, re-pushing and undoing never touch the ledger. The
     * generation was charged when the AI succeeded; moving those bytes into Shopify is free.
     *
     * @param  array<string,mixed>  $details
     */
    public function pushTransitionTo(string $next, array $details = [], string $actor = ActivityEvent::ACTOR_MERCHANT): void
    {
        if ($next !== self::PUSH_NOT_PUSHED && ! $this->isApproved()) {
            throw new RuntimeException(sprintf(
                self::PUSH_BEFORE_APPROVAL_MESSAGE,
                $this->getKey() ?? 'new',
                (string) $this->review_status,
            ));
        }

        $current = $this->push_status ?? self::PUSH_NOT_PUSHED;

        if (! in_array($next, self::PUSH_TRANSITIONS[$current] ?? [], true)) {
            throw new RuntimeException(sprintf(
                self::ILLEGAL_PUSH_MESSAGE,
                $current,
                $next,
                $this->getKey() ?? 'new',
            ));
        }

        $this->push_status = $next;
        $this->save();

        app(ActivityRecorder::class)->record(
            kind: self::KIND_PUSH_STATUS_CHANGED,
            subject: $this,
            details: ['from' => $current, 'to' => $next, 'product_id' => (int) $this->product_id] + $details,
            siteId: $this->site_id,
            actor: $actor,
        );
    }

    /**
     * Guarded GENERATION status move. Only canonical transitions are legal; anything else
     * throws (a corrupt state can never be persisted). Every accepted move writes a
     * best-effort activity trace — which never blocks the money path.
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

    /**
     * Guarded REVIEW move (the merchant's judgement). Legal only on a SUCCEEDED asset — an
     * image that does not exist cannot be approved or rejected — and only along the review
     * machine. A rejection does NOT reverse the charge: the generation already ran and the
     * provider billed us, and the studio UI says so before the batch starts.
     *
     * AN IMAGE THAT IS LIVE IN THE STORE CANNOT BE REJECTED. The two machines have to agree, or
     * the panel lies: rejecting a `pushed` (or in-flight `pushing`) asset would say "this image is
     * rejected" while the shopper is still looking at it on the storefront. Undo the push first —
     * that is the action that actually takes it down — and then reject.
     */
    public function reviewTransitionTo(string $next, string $actor = ActivityEvent::ACTOR_MERCHANT): void
    {
        if (! $this->isSucceeded()) {
            throw new RuntimeException(sprintf(
                self::REVIEW_BEFORE_SUCCESS_MESSAGE,
                $this->getKey() ?? 'new',
                $this->status,
            ));
        }

        if ($next === self::REVIEW_REJECTED && $this->isInStore()) {
            throw new RuntimeException(sprintf(
                self::REJECT_WHILE_PUSHED_MESSAGE,
                $this->getKey() ?? 'new',
                (string) $this->push_status,
            ));
        }

        $current = $this->review_status ?? self::REVIEW_AWAITING;

        if (! in_array($next, self::REVIEW_TRANSITIONS[$current] ?? [], true)) {
            throw new RuntimeException(sprintf(
                self::ILLEGAL_REVIEW_MESSAGE,
                $current,
                $next,
                $this->getKey() ?? 'new',
            ));
        }

        $this->review_status = $next;
        $this->save();

        app(ActivityRecorder::class)->record(
            kind: $next === self::REVIEW_APPROVED ? self::KIND_APPROVED : self::KIND_REJECTED,
            subject: $this,
            details: ['from' => $current, 'to' => $next, 'product_id' => (int) $this->product_id],
            siteId: $this->site_id,
            actor: $actor,
        );
    }
}
