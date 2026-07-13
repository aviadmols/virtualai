<?php

namespace App\Domain\Shopify\Media;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Jobs\TenantAwareJob;
use App\Models\ActivityEvent;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ShopifyMediaSnapshot;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * UndoProductMediaJob — "restore my original images" for ONE product.
 *
 * It replays the snapshot taken before the first destructive push: every original that Shopify no
 * longer has is re-uploaded from OUR bytes, the original order (and so the original featured
 * image) is restored, and only THEN are the images we added removed from the gallery.
 *
 * IT CAN NEVER DESTROY ANYTHING UNRECOVERABLE:
 *   - an original is never deleted here — only ADDED BACK;
 *   - our own pushed media is deleted only after every original is present and READY, and its
 *     bytes are still on our disk (product_assets.image_path), so it can simply be pushed again;
 *   - the SNAPSHOT ITSELF IS KEPT (restored_at / restore_count are stamped, nothing is dropped),
 *     which is what makes a second undo a clean no-op instead of a second, emptier "restore".
 *
 * IDEMPOTENT: re-running finds the originals already live (nothing to re-upload), reorders them
 * into the same order (a no-op), and finds nothing of ours left to delete.
 *
 * A push is free and so is an undo: nothing here touches the credit ledger.
 */
final class UndoProductMediaJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const CFG_QUEUE = 'trayon.queues.bulk';

    private const CFG_MAX_PARKS = 'shopify.media.max_parks';

    private const DEFAULT_MAX_PARKS = 20;

    private const IDEMPOTENCY_PREFIX = 'shopify_media_undo';

    private const UNIQUE_FOR_SECONDS = 900;

    private const PARK_SECONDS = 30;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = self::UNIQUE_FOR_SECONDS;

    private const LOG_RESTORED = 'shopify.media.restored';

    private const LOG_PARKED = 'shopify.media.undo_parked';

    private const LOG_FAILED = 'shopify.media.undo_failed';

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly int $productId,
        public readonly int $parks = 0,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config(self::CFG_QUEUE));
    }

    /** One lock per (account, site, product, park) — see PushProductMediaJob on the park index. */
    public function uniqueId(): string
    {
        return implode(':', [self::IDEMPOTENCY_PREFIX, $this->accountId, $this->siteId, $this->productId, $this->parks]);
    }

    public function failed(?Throwable $e): void
    {
        Tenant::run($this->accountId, function () use ($e): void {
            Log::error(self::LOG_FAILED, $this->context(['exception' => $e !== null ? $e::class : null]));
        });
    }

    protected function process(): void
    {
        $site = Site::query()->findOrFail($this->siteId);
        $product = Product::query()->findOrFail($this->productId);
        $snapshot = $this->snapshot();

        if ($snapshot === null || ! $snapshot->isCaptured()) {
            return; // nothing was ever destroyed on this product — there is nothing to undo
        }

        $pushed = $this->pushedAssets();

        try {
            $removed = $this->pusher()->restore($snapshot, $product, $site, $pushed);
        } catch (ShopifyApiException $e) {
            if ($e->isThrottled() && $this->parks < $this->maxParks()) {
                $this->park($e);

                return;
            }

            throw $e;
        }

        // The store no longer shows our images -> the assets are not_pushed again. Their bytes are
        // still ours, so the merchant can push any of them again, for free.
        foreach ($pushed as $asset) {
            $asset->forceFill([
                'shopify_media_id' => null,
                'push_error' => null,
                'pushed_at' => null,
                'push_placement' => null,
                'push_position' => null,
                'push_replaced_media_id' => null,
            ])->save();

            $asset->pushTransitionTo(ProductAsset::PUSH_NOT_PUSHED, ['reason' => 'undo']);
        }

        $snapshot->recordRestore();

        app(ActivityRecorder::class)->record(
            kind: ActivityEvent::KIND_SHOPIFY_MEDIA_RESTORED,
            subject: $snapshot,
            details: [
                'product_id' => $this->productId,
                'originals' => count($snapshot->entries()),
                'removed' => count($removed),
                'restore_count' => (int) $snapshot->restore_count,
            ],
            siteId: $this->siteId,
        );

        Log::info(self::LOG_RESTORED, $this->context(['removed' => count($removed)]));
    }

    /** The snapshot of this product's ORIGINAL gallery (tenant-scoped; fail closed). */
    private function snapshot(): ?ShopifyMediaSnapshot
    {
        return ShopifyMediaSnapshot::query()
            ->where('product_id', $this->productId)
            ->first();
    }

    /**
     * Every asset of this product that is currently IN the store — the images undo removes. A
     * push_failed asset that never got a media id is left alone.
     *
     * @return array<int,ProductAsset>
     */
    private function pushedAssets(): array
    {
        return ProductAsset::query()
            ->where('site_id', $this->siteId)
            ->where('product_id', $this->productId)
            ->where('push_status', ProductAsset::PUSH_PUSHED)
            ->whereNotNull('shopify_media_id')
            ->get()
            ->all();
    }

    private function park(ShopifyApiException $e): void
    {
        Log::warning(self::LOG_PARKED, $this->context(['code' => $e->errorCode, 'parks' => $this->parks]));

        self::dispatch($this->accountId, $this->siteId, $this->productId, $this->parks + 1)
            ->delay(self::PARK_SECONDS);
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
            'product_id' => $this->productId,
        ] + $extra;
    }

    private function pusher(): ShopifyMediaPusher
    {
        return app(ShopifyMediaPusher::class);
    }
}
