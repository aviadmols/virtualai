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
 * SyncShopifyCatalogJob — walk the store's catalog ONE cursor page at a time.
 *
 * Resumable BY CONSTRUCTION: the page's endCursor is persisted on the sync run BEFORE
 * the next page is dispatched, and the job re-dispatches ITSELF for that cursor. A
 * worker restart, a deploy, or a spent throttle budget therefore costs at most the
 * page in flight — never the whole walk. The run row (not the queue) is the truth.
 *
 * Idempotency: uniqueId is (account, site, run, cursor, park), so a double-dispatch of
 * the SAME page is dropped, while the self-redispatch (a NEW cursor) is never blocked by
 * its own lock.
 *
 * THE COMPLETENESS LAW. archiveStale() ("anything Shopify did not return is gone from the
 * store") is only TRUE of a walk that actually finished. A walk stopped by the page budget
 * saw part of the catalog, so its silence about a product proves nothing — sweeping there
 * would archive LIVE products and drop them out of the widget. So the sweep runs only when
 * the traversal genuinely ended (! hasNextPage); a budget-truncated walk still COMPLETES
 * (its pages are imported and correct) but sweeps NOTHING and is marked truncated.
 *
 * Throttle: ShopifyGraphQLClient already honours Retry-After and retries within its
 * budget; when the budget is spent it throws the typed CODE_THROTTLED. We then PARK — and
 * a park is NOT a failure, so it must not consume one of $tries (see park()).
 *
 * Tenant-safety: extends TenantAwareJob (explicit constructor account_id; Tenant::run
 * binds and clears in finally). No ambient tenant is ever read.
 */
final class SyncShopifyCatalogJob extends TenantAwareJob implements ShouldBeUnique
{
    // === CONSTANTS ===
    private const CFG_QUEUE = 'trayon.queues.bulk';

    private const CFG_MAX_PAGES = 'shopify.sync.max_pages';

    private const DEFAULT_MAX_PAGES = 400;

    // How many times one page may be parked (throttled) before the run is called stalled.
    private const CFG_MAX_PARKS = 'shopify.sync.max_parks';

    private const DEFAULT_MAX_PARKS = 40;

    private const IDEMPOTENCY_PREFIX = 'shopify_catalog';

    private const CURSOR_START = 'start';

    // How long the per-page unique lock may be held before it is considered stale.
    private const UNIQUE_FOR_SECONDS = 3600;

    // A parked (throttled) page comes back after this delay when Shopify gave no hint.
    private const PARK_SECONDS = 30;

    // The run's last_error when a store throttled us for the whole park budget.
    private const ERROR_THROTTLED_OUT = 'shopify_throttled';

    private const LOG_PARKED = 'shopify.sync.parked';

    private const LOG_TRUNCATED = 'shopify.sync.truncated';

    private const LOG_STALLED = 'shopify.sync.throttled_out';

    private const LOG_FAILED = 'shopify.sync.failed';

    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [15, 30, 60, 120];

    public int $uniqueFor = self::UNIQUE_FOR_SECONDS;

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly int $syncRunId,
        public readonly ?string $cursor = null,
        public readonly int $parks = 0,
    ) {
        parent::__construct($accountId);
        // Read the queue from config (config:cache makes the bare Q_BULK const undefined
        // at runtime, but the cached array still holds the value).
        $this->onQueue((string) config(self::CFG_QUEUE));
    }

    /**
     * One lock per (account, site, run, cursor, park) — the page ATTEMPT, not the walk.
     *
     * The park counter is part of the key BY DESIGN: this job's own unique lock is held
     * until handle() returns, so a re-dispatch of the same cursor from INSIDE handle()
     * under an identical key would be silently dropped and the walk would stall forever.
     * A concurrent double-dispatch of the same page still collapses (same park index).
     */
    public function uniqueId(): string
    {
        return implode(':', [
            self::IDEMPOTENCY_PREFIX,
            $this->accountId,
            $this->siteId,
            $this->syncRunId,
            $this->cursor ?? self::CURSOR_START,
            $this->parks,
        ]);
    }

    /** Runs with $this->accountId bound by TenantAwareJob::handle(). */
    protected function process(): void
    {
        $site = Site::query()->findOrFail($this->siteId);
        $run = ShopifySyncRun::query()->find($this->syncRunId);

        if ($run === null || $run->isTerminal()) {
            return; // the run was cancelled/completed under another trigger — idempotent
        }

        if (! $run->isRunning()) {
            $run->transitionTo(ShopifySyncRun::STATUS_RUNNING);
        }

        try {
            $page = $this->source()->page($site, $this->cursor);
        } catch (ShopifyApiException $e) {
            if ($e->isThrottled()) {
                $this->park($run, $e);

                return;
            }

            throw $e; // transport / auth / graphql: the queue retries, failed() marks the run
        }

        foreach ($page->entries as [$mapped, $ref]) {
            $this->importer()->importMapped($site, $mapped, $ref, $run);
        }

        $run->increment(ShopifySyncRun::COUNTER_TOTAL_SEEN, $page->count());
        $run->increment('pages');
        $run->refresh();
        $run->cursor = $page->endCursor;
        $run->save();

        if ($page->hasNextPage && $run->pages < $this->maxPages()) {
            self::dispatch($this->accountId, $this->siteId, $this->syncRunId, $page->endCursor);

            return;
        }

        // THE COMPLETENESS GUARD. Sweep ONLY when the traversal actually ended. A walk cut
        // short by the page budget has NOT seen the whole catalog, so "Shopify did not
        // return this product" is not evidence that it is gone — archiving on it would
        // wipe live products the budget simply never reached.
        if ($page->hasNextPage) {
            $this->recordTruncation($run);
        } else {
            $this->importer()->archiveStale($site, $run);
        }

        $run->transitionTo(ShopifySyncRun::STATUS_COMPLETED);

        app(ActivityRecorder::class)->record(
            kind: ActivityEvent::KIND_SHOPIFY_SYNC_COMPLETED,
            subject: $run,
            details: [
                'mode' => $run->mode,
                'total_seen' => $run->total_seen,
                'imported' => $run->imported,
                'updated' => $run->updated,
                'archived' => $run->archived,
                'pages' => $run->pages,
                'truncated' => $run->isTruncated(),
                'correlation_id' => $run->correlation_id,
            ],
            siteId: $this->siteId,
        );
    }

    /**
     * The walk hit its page budget with pages still unread: mark the run truncated (so
     * nothing downstream mistakes it for a completeness statement) and tell the merchant.
     */
    private function recordTruncation(ShopifySyncRun $run): void
    {
        $run->markTruncated(ShopifySyncRun::TRUNCATION_MAX_PAGES);

        Log::warning(self::LOG_TRUNCATED, [
            'correlation_id' => $run->correlation_id,
            'sync_run_id' => $run->getKey(),
            'account_id' => $this->accountId,
            'site_id' => $this->siteId,
            'pages' => $run->pages,
            'max_pages' => $this->maxPages(),
        ]);

        app(ActivityRecorder::class)->record(
            kind: ActivityEvent::KIND_SHOPIFY_SYNC_TRUNCATED,
            subject: $run,
            details: [
                'reason' => $run->truncated_reason,
                'pages' => $run->pages,
                'max_pages' => $this->maxPages(),
                'total_seen' => $run->total_seen,
                'correlation_id' => $run->correlation_id,
            ],
            siteId: $this->siteId,
        );
    }

    /**
     * The run stays RUNNING with its cursor parked; the page comes back after the wait.
     *
     * A PARK IS NOT A FAILURE, so it must not spend a try. release() would: the worker
     * increments attempts on every reservation, so five throttled pages in a row would
     * exhaust $tries and FAIL the run — and FAILED is terminal, it never re-opens. A store
     * that is merely rate-limiting us would lose its whole import.
     *
     * So this job COMPLETES (nothing is lost — the cursor is already persisted) and queues
     * a FRESH one for the SAME cursor after the wait: attempt 1 again, with the park count
     * carried so the stall is still bounded.
     */
    private function park(ShopifySyncRun $run, ShopifyApiException $e): void
    {
        Log::warning(self::LOG_PARKED, [
            'correlation_id' => $run->correlation_id,
            'sync_run_id' => $run->getKey(),
            'account_id' => $this->accountId,
            'site_id' => $this->siteId,
            'cursor' => $this->cursor,
            'parks' => $this->parks,
            'code' => $e->errorCode,
        ]);

        if ($this->parks >= $this->maxParks()) {
            $this->stall($run);

            return;
        }

        self::dispatch(
            $this->accountId,
            $this->siteId,
            $this->syncRunId,
            $this->cursor,
            $this->parks + 1,
        )->delay(self::PARK_SECONDS);
    }

    /** The park budget is spent: the store has throttled us out. A real, reported stop. */
    private function stall(ShopifySyncRun $run): void
    {
        Log::error(self::LOG_STALLED, [
            'correlation_id' => $run->correlation_id,
            'sync_run_id' => $run->getKey(),
            'account_id' => $this->accountId,
            'site_id' => $this->siteId,
            'parks' => $this->parks,
        ]);

        $run->transitionTo(ShopifySyncRun::STATUS_FAILED, self::ERROR_THROTTLED_OUT);

        app(ActivityRecorder::class)->record(
            kind: ActivityEvent::KIND_SHOPIFY_SYNC_FAILED,
            subject: $run,
            details: [
                'error' => self::ERROR_THROTTLED_OUT,
                'parks' => $this->parks,
                'correlation_id' => $run->correlation_id,
            ],
            siteId: $this->siteId,
        );
    }

    /** The queue gave up on this page: the run is FAILED, with the reason on the row. */
    public function failed(?Throwable $e): void
    {
        Tenant::run($this->accountId, function () use ($e): void {
            $run = ShopifySyncRun::query()->find($this->syncRunId);

            if ($run === null || $run->isTerminal()) {
                return;
            }

            $run->transitionTo(ShopifySyncRun::STATUS_FAILED, $e?->getMessage());

            Log::error(self::LOG_FAILED, [
                'correlation_id' => $run->correlation_id,
                'sync_run_id' => $run->getKey(),
                'account_id' => $this->accountId,
                'site_id' => $this->siteId,
                'exception' => $e !== null ? $e::class : null,
            ]);

            app(ActivityRecorder::class)->record(
                kind: ActivityEvent::KIND_SHOPIFY_SYNC_FAILED,
                subject: $run,
                details: ['error' => $e?->getMessage(), 'correlation_id' => $run->correlation_id],
                siteId: $this->siteId,
            );
        });
    }

    private function maxPages(): int
    {
        return (int) (config(self::CFG_MAX_PAGES) ?? self::DEFAULT_MAX_PAGES);
    }

    private function maxParks(): int
    {
        return (int) (config(self::CFG_MAX_PARKS) ?? self::DEFAULT_MAX_PARKS);
    }

    private function source(): ShopifyProductSource
    {
        return app(ShopifyProductSource::class);
    }

    private function importer(): ShopifyProductImporter
    {
        return app(ShopifyProductImporter::class);
    }
}
