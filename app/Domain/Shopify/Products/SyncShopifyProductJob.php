<?php

namespace App\Domain\Shopify\Products;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Jobs\TenantAwareJob;
use App\Models\ActivityEvent;
use App\Models\ShopifySyncRun;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SyncShopifyProductJob — import (or refresh) ONE Shopify product by GID.
 *
 * Used by the merchant's multi-select import and as the retryable unit behind a
 * products/update webhook. ShouldBeUnique PER GID: a double-clicked import, a replayed
 * webhook, and a queue retry all collapse to one in-flight import of that product.
 *
 * A product Shopify no longer has (404) is ARCHIVED, not retried forever — retrying a
 * deleted product is how a sync run dies. A throttle PARKS the job — and a park is NOT a
 * failure, so it must not spend one of $tries (see park(), the same law the catalog walk
 * follows); the run's counters are the durable progress.
 */
final class SyncShopifyProductJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const CFG_QUEUE = 'trayon.queues.bulk';

    private const IDEMPOTENCY_PREFIX = 'shopify_product';

    private const UNIQUE_FOR_SECONDS = 900;

    private const PARK_SECONDS = 30;

    // How many times ONE product may be parked (throttled) before it is a real error.
    private const CFG_MAX_PARKS = 'shopify.sync.max_parks';

    private const DEFAULT_MAX_PARKS = 40;

    private const LOG_PARKED = 'shopify.product_sync.parked';

    private const LOG_MISSING = 'shopify.product_sync.not_found';

    private const LOG_FAILED = 'shopify.product_sync.failed';

    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [15, 30, 60, 120];

    public int $uniqueFor = self::UNIQUE_FOR_SECONDS;

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly string $gid,
        public readonly ?int $syncRunId = null,
        public readonly int $parks = 0,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config(self::CFG_QUEUE));
    }

    /**
     * One lock per (account, site, product GID, park) — the import ATTEMPT of that product.
     *
     * A double-clicked import, a replayed webhook and a queue retry all carry parks=0 and still
     * collapse onto one lock. The park index is in the key for the same reason the catalog walk
     * carries it: this job's own lock is held until handle() returns, so a re-dispatch from
     * INSIDE handle() under an identical key would be silently dropped and the product would
     * never be imported.
     */
    public function uniqueId(): string
    {
        return implode(':', [self::IDEMPOTENCY_PREFIX, $this->accountId, $this->siteId, $this->gid, $this->parks]);
    }

    protected function process(): void
    {
        $site = Site::query()->findOrFail($this->siteId);
        $run = $this->run();

        if ($run !== null && ! $run->isRunning() && ! $run->isTerminal()) {
            $run->transitionTo(ShopifySyncRun::STATUS_RUNNING);
        }

        try {
            $this->importer()->importOne($site, $this->gid, $run);
        } catch (ShopifyProductNotFoundException $e) {
            // Gone from the store: archive locally (never delete — the FKs from past
            // generations must survive) and count it, rather than retry into the void.
            Log::info(self::LOG_MISSING, $this->context(['gid' => $this->gid]));

            $this->importer()->archiveByGid($site, $this->gid, $run);
        } catch (ShopifyApiException $e) {
            if ($e->isThrottled() && $this->parks < $this->maxParks()) {
                $this->park($e);

                return;
            }

            throw $e;
        }

        $this->completeRunIfDone($run);
    }

    /**
     * The store is rate-limiting us: come back later, WITHOUT spending a try.
     *
     * release() would spend one: the worker increments attempts on every reservation, so five
     * throttled products in a row would exhaust $tries and FAIL this product for good — a store
     * that is merely busy would lose it from the import. So this job COMPLETES (nothing is lost:
     * an unimported product has no partial state) and queues a FRESH one for the SAME GID after
     * the wait — attempt 1 again, with the park count carried so the stall is still bounded. Past
     * the park budget the throttle is a real error and takes the normal failure path.
     */
    private function park(ShopifyApiException $e): void
    {
        Log::warning(self::LOG_PARKED, $this->context(['code' => $e->errorCode, 'parks' => $this->parks]));

        self::dispatch(
            $this->accountId,
            $this->siteId,
            $this->gid,
            $this->syncRunId,
            $this->parks + 1,
        )->delay(self::PARK_SECONDS);
    }

    /** How many parks one product may take before the throttle is treated as a failure. */
    private function maxParks(): int
    {
        return (int) (config(self::CFG_MAX_PARKS) ?? self::DEFAULT_MAX_PARKS);
    }

    /**
     * A selection run finishes when every requested GID has been processed. The counters
     * on the run row are the truth (no in-memory batch state that a restart would lose).
     */
    private function completeRunIfDone(?ShopifySyncRun $run): void
    {
        if ($run === null || $run->mode !== ShopifySyncRun::MODE_SELECTION) {
            return;
        }

        $run->refresh();

        $requested = count((array) ($run->requested_gids ?? []));
        $done = $run->processed() + (int) $run->archived;

        if ($requested === 0 || $done < $requested || $run->isTerminal()) {
            return;
        }

        $run->total_seen = $requested;
        $run->save();
        $run->transitionTo(ShopifySyncRun::STATUS_COMPLETED);

        app(ActivityRecorder::class)->record(
            kind: ActivityEvent::KIND_SHOPIFY_SYNC_COMPLETED,
            subject: $run,
            details: [
                'mode' => $run->mode,
                'imported' => $run->imported,
                'updated' => $run->updated,
                'archived' => $run->archived,
                'correlation_id' => $run->correlation_id,
            ],
            siteId: $this->siteId,
        );
    }

    public function failed(?Throwable $e): void
    {
        Tenant::run($this->accountId, function () use ($e): void {
            Log::error(self::LOG_FAILED, $this->context(['exception' => $e !== null ? $e::class : null]));

            $run = $this->run();

            if ($run === null || $run->isTerminal()) {
                return;
            }

            $run->increment(ShopifySyncRun::COUNTER_FAILED);
            $run->refresh();
            $run->last_error = $e?->getMessage();
            $run->save();

            $this->completeRunIfDone($run);
        });
    }

    private function run(): ?ShopifySyncRun
    {
        return $this->syncRunId === null
            ? null
            : ShopifySyncRun::query()->find($this->syncRunId);
    }

    /** @return array<string,mixed> */
    private function context(array $extra = []): array
    {
        return [
            'account_id' => $this->accountId,
            'site_id' => $this->siteId,
            'sync_run_id' => $this->syncRunId,
            'gid' => $this->gid,
        ] + $extra;
    }

    private function importer(): ShopifyProductImporter
    {
        return app(ShopifyProductImporter::class);
    }
}
