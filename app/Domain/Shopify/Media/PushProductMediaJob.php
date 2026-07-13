<?php

namespace App\Domain\Shopify\Media;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Jobs\TenantAwareJob;
use App\Models\ActivityEvent;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PushProductMediaJob — put ONE approved image into the store's product media, at the placement
 * the merchant chose. It is the ONLY writer of the push machine.
 *
 * A PUSH IS FREE. It reserves nothing, charges nothing, and writes no credit_ledger row: the AI
 * ran and was paid for when the asset succeeded. A re-push therefore retries the PUSH ONLY and
 * can never re-run a generation — which is exactly why the push machine is a SEPARATE machine
 * from the generation machine and never collapses into it.
 *
 * EXACTLY ONE SHOPIFY MEDIA PER ASSET — Shopify has no idempotency key here, so the guarantee is
 * ours, in FOUR layers:
 *   1. ShouldBeUnique on (account, site, asset, park) — a double dispatch is dropped before it runs;
 *   2. a ROW-LOCKED LEASE — a second trigger that does run finds the asset already `pushing` under
 *      a FRESH lease and short-circuits. The lease re-stamps BOTH the claim id and `updated_at`
 *      inside the lock, so a reclaim of a "stuck" push and the original job's slow parked
 *      continuation can never both be admitted (they were: one minted a media the asset row then
 *      forgot, and it stayed live in the merchant's storefront forever);
 *   3. THE CLAIM, RE-PROVED BEFORE THE MINT — a worker whose lease was reclaimed out from under it
 *      stands down instead of minting a second media (ShopifyMediaPusher::createMedia);
 *   4. `shopify_media_id` — once Shopify has handed one back, the pusher NEVER uploads again; it
 *      resumes (the twin of provider_request_id on the generation rail).
 *
 * And if a media is EVER minted, shopify_media_mints remembers it — append-only, never nulled — so
 * even an orphan of a race we have not thought of yet can be taken back out of the store by Undo.
 *
 * A THROTTLE IS NOT A FAILURE. Shopify rate-limits by query cost; when the client's Retry-After
 * budget is spent it surfaces a typed CODE_THROTTLED, and this job PARKS: it completes and
 * re-dispatches itself after a delay, carrying its park index — which MUST be in uniqueId(), or
 * the redispatch would collide with the lock its own predecessor still holds and be silently
 * dropped (the Phase-3 scar, reproduced empirically) — AND its claim id, so the continuation
 * renews its OWN lease rather than looking like a stranger to it.
 */
final class PushProductMediaJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const CFG_QUEUE = 'trayon.queues.bulk';

    private const CFG_MAX_PARKS = 'shopify.media.max_parks';

    private const DEFAULT_MAX_PARKS = 20;

    private const IDEMPOTENCY_PREFIX = 'shopify_media_push';

    private const UNIQUE_FOR_SECONDS = 900;

    private const PARK_SECONDS = 30;

    // tries=1: the push is driven by explicit merchant action (push / re-push). An escaped
    // exception is caught by failed(), which closes the asset as push_failed with the reason.
    public int $tries = 1;

    // The READY poll (20 x 3s) plus two byte transfers must fit inside this.
    public int $timeout = 180;

    public int $uniqueFor = self::UNIQUE_FOR_SECONDS;

    private const LOG_PARKED = 'shopify.media.push_parked';

    private const LOG_FAILED = 'shopify.media.push_failed';

    private const LOG_PUSHED = 'shopify.media.pushed';

    // A worker whose lease was reclaimed out from under it. Not a failure — it just stands down.
    private const LOG_EVICTED = 'shopify.media.push_claim_lost';

    /** The lease this run holds on the asset. Minted (or renewed) inside the row-locked claim. */
    private ?string $claim = null;

    /**
     * @param  array<string,mixed>  $placement  MediaPlacement::toArray() (jobs serialize scalars)
     * @param  string|null  $claimId  the lease a PARKED continuation carries back to renew its own
     *                                claim. A fresh push / a merchant reclaim carries none.
     */
    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly int $productAssetId,
        public readonly array $placement,
        public readonly int $parks = 0,
        public readonly ?string $claimId = null,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config(self::CFG_QUEUE));
    }

    /**
     * One lock per (account, site, asset, park). A double-clicked Push carries parks=0 for both
     * dispatches and collapses onto ONE lock; a parked retry carries its own index so it is not
     * swallowed by the lock its predecessor is still holding.
     */
    public function uniqueId(): string
    {
        return implode(':', [self::IDEMPOTENCY_PREFIX, $this->accountId, $this->siteId, $this->productAssetId, $this->parks]);
    }

    /** Last-resort net: an escaped throw still closes the asset with a reason the merchant sees. */
    public function failed(?Throwable $e): void
    {
        Tenant::run($this->accountId, function () use ($e): void {
            $asset = ProductAsset::query()->find($this->productAssetId);

            if ($asset === null || ! $asset->isPushing()) {
                return;
            }

            $this->failPush($asset, $e?->getMessage() ?? 'The push failed.', []);
        });
    }

    protected function process(): void
    {
        $asset = $this->lockAndClaim();

        if ($asset === null) {
            return; // already pushed / in flight under another trigger / not approved — idempotent
        }

        $site = Site::query()->findOrFail($this->siteId);
        $product = Product::query()->findOrFail($asset->product_id);
        $placement = MediaPlacement::fromArray($this->placement);

        try {
            $mediaId = $this->pusher()->push($asset, $product, $site, $placement, (string) $this->claim);
        } catch (ShopifyApiException $e) {
            if ($e->isThrottled() && $this->parks < $this->maxParks()) {
                $this->park($e);

                return;
            }

            $this->failPush($asset, $e->getMessage(), []);

            return;
        } catch (PushClaimLostException) {
            // Another worker holds the lease now (a reclaim overtook us). This is NOT a failure and
            // must NOT touch the asset: the holder of the lease owns the outcome. Stand down.
            Log::info(self::LOG_EVICTED, $this->context(['claim' => $this->claim]));

            return;
        } catch (ShopifyMediaException $e) {
            // mediaUserErrors, verbatim — they are the merchant's only explanation.
            $this->failPush($asset, $e->getMessage(), $e->errors);

            return;
        } catch (Throwable $e) {
            $this->failPush($asset, $e->getMessage(), []);

            return;
        }

        $this->succeed($asset, $mediaId, $placement);
    }

    /**
     * Row-lock + LEASE. Returns the asset ONLY when this job may push it.
     *
     * Refusals (all idempotent, none an error): an unapproved image (the merchant's judgement is
     * what unlocks the storefront), an asset already pushed, or one whose push lease is held by a
     * LIVING worker.
     *
     * THE LEASE IS THE WHOLE POINT. A `pushing` asset is admitted in exactly two cases, and both
     * re-stamp the lease before returning:
     *
     *   - THIS job's parked continuation, which carries its own claim id back (holdsPushClaim);
     *   - a RECLAIM of a push whose worker is LOST (isPushStuck) — it mints a FRESH claim id, which
     *     EVICTS the old worker: before it may mint Shopify media, that worker re-proves its claim
     *     and finds it gone.
     *
     * The old code admitted on `parks > 0 || isPushStuck()` and re-stamped NOTHING. A reclaim and a
     * slow parked continuation were therefore both admitted in the same stuck window — because the
     * claim never touched `updated_at`, the field isPushStuck() judges freshness by. Two workers,
     * two productCreateMedia calls, ONE asset row to hold the id: the media the row forgot stayed
     * live in the merchant's storefront and no Undo could reach it.
     */
    private function lockAndClaim(): ?ProductAsset
    {
        return DB::transaction(function (): ?ProductAsset {
            /** @var ProductAsset $asset */
            $asset = ProductAsset::query()->lockForUpdate()->findOrFail($this->productAssetId);

            if (! $asset->isApproved() || $asset->isPushed()) {
                return null;
            }

            if ($asset->isPushing()) {
                $mine = $asset->holdsPushClaim($this->claimId);

                if (! $mine && ! $asset->isPushStuck()) {
                    return null; // a living worker holds the lease — there is nothing for us to do
                }

                // Renew my own lease, or TAKE it (a fresh id evicts the lost worker). Either way
                // updated_at is re-stamped: the next reclaimer sees a lease that is seconds old.
                $this->claim = $asset->takePushLease($mine ? $this->claimId : null);

                return $asset;
            }

            $placement = MediaPlacement::fromArray($this->placement);

            $asset->forceFill([
                'push_placement' => $placement->mode,
                'push_position' => $placement->position,
                'push_replaced_media_id' => $placement->replaceMediaId,
                'push_error' => null,
            ])->save();

            $this->claim = $asset->takePushLease();

            $asset->pushTransitionTo(ProductAsset::PUSH_PUSHING, ['placement' => $placement->toArray()]);

            return $asset;
        });
    }

    /** The image is live in the store, at the slot the merchant asked for. */
    private function succeed(ProductAsset $asset, string $mediaId, MediaPlacement $placement): void
    {
        $asset->forceFill([
            'shopify_media_id' => $mediaId,
            'push_error' => null,
            'pushed_at' => now(),
            'push_claim_id' => null, // the push is over — the lease is released
        ])->save();

        $asset->pushTransitionTo(ProductAsset::PUSH_PUSHED, [
            'shopify_media_id' => $mediaId,
            'placement' => $placement->toArray(),
        ]);

        app(ActivityRecorder::class)->record(
            kind: ActivityEvent::KIND_SHOPIFY_MEDIA_PUSHED,
            subject: $asset,
            details: [
                'product_id' => (int) $asset->product_id,
                'shopify_media_id' => $mediaId,
                'placement' => $placement->mode,
                'position' => $placement->position,
                'replaced_media_id' => $placement->replaceMediaId,
            ],
            siteId: $this->siteId,
        );

        Log::info(self::LOG_PUSHED, $this->context(['shopify_media_id' => $mediaId, 'placement' => $placement->mode]));
    }

    /**
     * The push did not land. The reason is stored VERBATIM on push_error (Shopify's mediaUserErrors
     * are the merchant's only explanation) and the asset becomes push_failed — re-pushable, for
     * free, without ever re-running the AI.
     *
     * @param  array<int,string>  $errors
     */
    private function failPush(ProductAsset $asset, string $message, array $errors): void
    {
        Log::warning(self::LOG_FAILED, $this->context(['message' => $message]));

        // The lease is released with the failure: a re-push must not have to wait out a stuck
        // window to take a claim nobody is holding any more.
        $asset->forceFill(['push_error' => $message, 'push_claim_id' => null])->save();

        if ($asset->isPushing()) {
            $asset->pushTransitionTo(ProductAsset::PUSH_FAILED, ['message' => $message], ActivityEvent::ACTOR_SYSTEM);
        }

        app(ActivityRecorder::class)->record(
            kind: ActivityEvent::KIND_SHOPIFY_MEDIA_PUSH_FAILED,
            subject: $asset,
            details: ['product_id' => (int) $asset->product_id, 'message' => $message, 'errors' => $errors],
            siteId: $this->siteId,
            actor: ActivityEvent::ACTOR_SYSTEM,
        );
    }

    /**
     * The store is rate-limiting us: come back later WITHOUT spending a try and WITHOUT touching
     * the asset (it stays `pushing`, which is the truth — the push is still in flight). The park
     * index rides in uniqueId(), or this redispatch would be dropped by its own predecessor's lock.
     *
     * THE CLAIM RIDES WITH IT. The continuation is the SAME push, so it carries the same lease and
     * renews it — otherwise it would look like a stranger to its own claim and be refused (or, in
     * the world before the lease existed, be admitted alongside a reclaim and mint a second media).
     */
    private function park(ShopifyApiException $e): void
    {
        Log::warning(self::LOG_PARKED, $this->context(['code' => $e->errorCode, 'parks' => $this->parks]));

        self::dispatch(
            $this->accountId,
            $this->siteId,
            $this->productAssetId,
            $this->placement,
            $this->parks + 1,
            $this->claim,
        )->delay(self::PARK_SECONDS);
    }

    private function maxParks(): int
    {
        return (int) (config(self::CFG_MAX_PARKS) ?? self::DEFAULT_MAX_PARKS);
    }

    /** @return array<string,mixed> */
    private function context(array $extra = []): array
    {
        return [
            'account_id' => $this->accountId,
            'site_id' => $this->siteId,
            'product_asset_id' => $this->productAssetId,
        ] + $extra;
    }

    private function pusher(): ShopifyMediaPusher
    {
        return app(ShopifyMediaPusher::class);
    }
}
