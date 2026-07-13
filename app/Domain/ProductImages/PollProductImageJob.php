<?php

namespace App\Domain\ProductImages;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\AsyncImageTicket;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\ProductImageCaller;
use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\Reservation;
use App\Domain\Credits\ReservationManager;
use App\Domain\Generation\GenerationFailureCode;
use App\Jobs\TenantAwareJob;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PollProductImageJob — the SECOND half of one bulk product-image transform: it polls the
 * provider's queue ticket in short, re-dispatching ticks until the render is terminal, then
 * finalizes (store -> charge on success / release with NO charge on failure).
 *
 * THE THREE THINGS THIS JOB EXISTS TO GUARANTEE:
 *
 *  1. A NETWORK BLIP RETRIES THE POLL, NEVER THE SUBMIT. The ticket persisted at submit time IS
 *     the render the provider already accepted. A transport failure here re-dispatches another
 *     poll of the SAME request id — it can never start a second render (which we would pay for
 *     twice).
 *
 *  2. THE RESERVATION HOLD IS RENEWED ON EVERY TICK. The in-flight hold has a TTL (300s) that a
 *     multi-minute render would outlive. If it lapsed, the terminal release would find no key,
 *     skip its decrement, and strand the hold on accounts.reserved_micro_usd FOREVER — quietly
 *     destroying the merchant's spendable credit. So every tick re-stamps it, under the asset's
 *     row lock (the same lock the terminal release takes, so renew and release can never race).
 *
 *  3. THE POLL BUDGET IS BOUNDED. MAX_ATTEMPTS × DELAY is the ceiling; exhausting it is a
 *     terminal failure with the hold released and NO charge row. We never bill for a render we
 *     never received.
 *
 * NOT ShouldBeUnique — deliberately. This job RE-DISPATCHES ITSELF, and a unique lock still held
 * by the running tick would silently swallow its own continuation, freezing the asset in
 * `processing` forever. Concurrency safety comes from the row lock + the status pre-check below:
 * a duplicate tick sees a non-processing asset and no-ops.
 */
final class PollProductImageJob extends TenantAwareJob
{
    // === CONSTANTS ===
    private const REFERENCE_TYPE = CreditLedger::REFERENCE_PRODUCT_ASSET;

    // tries=1: a queue retry must never re-enter the money path. failed() closes the asset.
    public int $tries = 1;

    public int $timeout = 60;

    // The poll budget: 10s × 60 = up to 10 minutes of render time per asset. It must stay well
    // inside the renewed reservation TTL (config trayon.credits.reservation_ttl = 300s), which
    // each tick re-stamps — so the hold can never lapse between two ticks.
    private const MAX_ATTEMPTS = 60;

    private const DELAY_SECONDS = 10;

    private const MSG_TICKET_MISSING = 'The provider ticket is missing; the render cannot be polled.';

    private const MSG_BUDGET_EXHAUSTED = 'The provider did not finish this image within the poll budget.';

    public function __construct(
        int $accountId,
        public readonly int $siteId,
        public readonly int $productAssetId,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config('trayon.queues.bulk'));
    }

    /**
     * An escaped exception must never strand a hold or a `processing` asset. Bind the tenant,
     * release by key (atomic + idempotent), close the asset. No charge ever ran here.
     */
    public function failed(Throwable $e): void
    {
        Tenant::run($this->accountId, function () use ($e): void {
            $asset = ProductAsset::query()->find($this->productAssetId);

            if ($asset === null || $asset->isTerminal()) {
                return;
            }

            $this->reservations()->releaseByKey($this->accountId, (string) $asset->idempotency_key);

            $this->finalizer()->fail(
                $asset,
                Reservation::forKey($this->accountId, (string) $asset->idempotency_key, 0),
                GenerationFailureCode::INTERNAL_ERROR,
                $e->getMessage(),
            );
        });
    }

    /** Runs with $this->accountId bound by TenantAwareJob::handle(). */
    protected function process(): void
    {
        $claim = $this->lockRenewAndClaim();

        if ($claim === null) {
            return; // terminal / already charged / gone — a duplicate tick is a no-op
        }

        [$asset, $reservation] = $claim;

        $ticket = AsyncImageTicket::fromArray(($asset->provider_meta ?? [])[ProductAsset::PROVIDER_META_TICKET] ?? null);

        if ($ticket === null) {
            $this->finalizer()->fail($asset, $reservation, GenerationFailureCode::INTERNAL_ERROR, self::MSG_TICKET_MISSING);

            return;
        }

        $site = Site::query()->findOrFail($this->siteId);
        $product = Product::query()->findOrFail($asset->product_id);
        $config = $this->resolver()->for($asset->operation_key, $site, $product->product_type ?: null);

        try {
            $poll = $this->caller()->poll($ticket, $config);
        } catch (Throwable $e) {
            // TRANSPORT problem — the render itself is untouched upstream. Re-POLL, never re-submit.
            $this->reschedule($asset, $reservation, $e->getMessage());

            return;
        }

        if ($poll->isPending()) {
            $this->reschedule($asset, $reservation, null);

            return;
        }

        if ($poll->isFailed()) {
            $this->finalizer()->fail($asset, $reservation, GenerationFailureCode::AI_CALL_FAILED, $poll->message);

            return;
        }

        $flatRate = ($asset->provider_meta ?? [])[ProductAsset::PROVIDER_META_FLAT_RATE_MICRO_USD] ?? null;

        try {
            $result = $this->caller()->resultFromPoll($poll, $ticket, is_numeric($flatRate) ? (int) $flatRate : null);
        } catch (OpenRouterException $e) {
            $this->finalizer()->fail($asset, $reservation, GenerationFailureCode::AI_CALL_FAILED, $e->getMessage());

            return;
        }

        $account = Account::query()->findOrFail($this->accountId);

        $this->finalizer()->succeed($asset, $account, $config, $result, $reservation);
    }

    /**
     * Row-lock the asset, confirm it is still an in-flight render, and RENEW its reservation
     * hold — all in one transaction, so the renew is serialised against the terminal release
     * that runs under the same lock (a renew can never resurrect an already-released hold).
     *
     * Returns [asset, reservation] or null when there is nothing left to poll.
     *
     * @return array{0: ProductAsset, 1: Reservation}|null
     */
    private function lockRenewAndClaim(): ?array
    {
        return DB::transaction(function (): ?array {
            /** @var ProductAsset|null $asset */
            $asset = ProductAsset::query()->lockForUpdate()->find($this->productAssetId);

            if ($asset === null || ! $asset->isProcessing()) {
                return null;
            }

            if ($this->ledger()->hasCharge((int) $asset->getKey(), self::REFERENCE_TYPE)) {
                return null; // a racing finalize already charged it
            }

            $reservation = Reservation::forKey(
                $this->accountId,
                (string) $asset->idempotency_key,
                (int) $asset->reserved_micro_usd,
            );

            // Keep the hold alive for another full TTL — see the class docblock, guarantee #2.
            $this->reservations()->renew($reservation);

            return [$asset, $reservation];
        });
    }

    /**
     * The render is not done (or a blip hid the answer): spend one unit of the poll budget and
     * schedule the next tick. When the budget is gone the asset fails TERMINALLY — hold released,
     * NO charge row: an image we never received is an image the merchant never pays for.
     */
    private function reschedule(ProductAsset $asset, Reservation $reservation, ?string $error): void
    {
        $attempts = (int) $asset->poll_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->finalizer()->fail(
                $asset,
                $reservation,
                GenerationFailureCode::POLL_TIMEOUT,
                $error ?? self::MSG_BUDGET_EXHAUSTED,
                ['poll_attempts' => $attempts],
            );

            return;
        }

        $asset->forceFill(['poll_attempts' => $attempts])->save();

        self::dispatch($this->accountId, $this->siteId, $this->productAssetId)
            ->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    // --- Resolved dependencies (jobs serialize only scalars; resolve at run time) ---

    private function ledger(): CreditLedgerService
    {
        return app(CreditLedgerService::class);
    }

    private function reservations(): ReservationManager
    {
        return app(ReservationManager::class);
    }

    private function resolver(): AiOperationResolver
    {
        return app(AiOperationResolver::class);
    }

    private function caller(): ProductImageCaller
    {
        return app(ProductImageCaller::class);
    }

    private function finalizer(): ProductImageFinalizer
    {
        return app(ProductImageFinalizer::class);
    }
}
