<?php

namespace App\Domain\Credits;

use App\Models\Account;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;

/**
 * ReservationManager — the I/O behind a Reservation. Holds / releases the
 * estimated max charge for an in-flight generation.
 *
 * Two layers, both required:
 *  1. accounts.reserved_micro_usd — the durable, transactional truth the CreditGate
 *     reads (spendable = balance − reserved). Incremented/decremented under a row
 *     lock so two concurrent reserves serialize and can't both pass on one balance.
 *  2. A short-lived cache (Redis in prod) key on the idempotency key — the in-flight
 *     window guard. Its TTL MUST exceed the generation job timeout
 *     (config trayon.credits.reservation_ttl, coordinated with OPENROUTER_TIMEOUT)
 *     so it never expires mid-generation.
 *
 * release() is ATOMIC + IDEMPOTENT: it claims the cache key with pull() (atomic
 * get-and-delete), and only the single caller that wins the pull decrements the
 * column. A concurrent double-release (failure path racing finalize, or two workers)
 * therefore decrements reserved EXACTLY once — the non-atomic has()+forget() it
 * replaced had a TOCTOU window where two releases could both pass has() before
 * either forget()'d and double-decrement (Phase-5 gatekeeper finding S1). The cache
 * key is claimed on reserve() with add() (atomic put-if-absent) so re-reserving the
 * same key is a no-op, not a double-hold.
 */
final class ReservationManager
{
    // === CONSTANTS ===
    private const CACHE_PREFIX = 'credit:reservation:';

    private const RESERVED_COLUMN = 'reserved_micro_usd';

    private const TTL_CONFIG_KEY = 'trayon.credits.reservation_ttl';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Reserve the estimate for $account against the deterministic key. Atomic:
     * the cache key is claimed first (put-if-absent); only a fresh claim increments
     * the column, so a retry of the same generation never double-reserves.
     */
    public function reserve(Account $account, string $idempotencyKey, int $estimateMicroUsd): Reservation
    {
        $reservation = Reservation::forKey($account->getKey(), $idempotencyKey, $estimateMicroUsd);

        // add() = atomic put-if-absent. A second reserve for the same in-flight
        // key returns false and we do NOT increment again (no double-hold).
        $claimed = $this->cache->add($this->cacheKey($idempotencyKey), $estimateMicroUsd, $this->ttl());

        if ($claimed) {
            $this->adjustReserved($account, $estimateMicroUsd);
        }

        return $reservation;
    }

    /**
     * Release the reservation. ATOMIC + idempotent (S1): pull() is an atomic
     * get-and-delete, so exactly ONE caller can win the key — and only that caller
     * decrements the column. A concurrent double-release (failure path racing a
     * finalize, or two workers) decrements reserved exactly once, never twice; the
     * losers get null from pull() and no-op. The non-atomic has()+forget() this
     * replaced had a TOCTOU window where two releases could both pass has() before
     * either deleted the key.
     */
    public function release(Reservation $reservation): void
    {
        $key = $this->cacheKey($reservation->id);

        // Atomic claim: only the caller that pulls a non-null value owns this
        // release and may decrement. A second release pulls null and no-ops.
        $held = $this->cache->pull($key);

        if ($held === null) {
            return;
        }

        $account = Account::query()->find($reservation->accountId);

        if ($account !== null) {
            $this->adjustReserved($account, -$reservation->estimateMicroUsd);
        }
    }

    /**
     * Release a reservation identified ONLY by its key — the failure-reaper path (a job's
     * failed() handler), where the original Reservation object isn't in hand. Atomic +
     * idempotent like release(): pull the key and decrement by the EXACT amount that was held
     * (the cached value), so it never over- or under-decrements even without the estimate.
     * A key already released (or never held) pulls null and no-ops.
     */
    public function releaseByKey(int $accountId, string $idempotencyKey): void
    {
        $held = $this->cache->pull($this->cacheKey($idempotencyKey));

        if ($held === null) {
            return;
        }

        $account = Account::query()->find($accountId);

        if ($account !== null) {
            $this->adjustReserved($account, -(int) $held);
        }
    }

    /**
     * RENEW an in-flight hold: re-stamp the cache key with the held amount and a FULL fresh
     * TTL. Used by a long-running ASYNC generation (the bulk product-image poller) on every
     * poll tick, so a render that outlives the TTL can never let the hold silently lapse.
     *
     * It deliberately does NOT touch accounts.reserved_micro_usd — and that is what makes it
     * safe in both directions:
     *  - key still present  -> the column already holds the estimate; we only extend the TTL.
     *  - key already lapsed -> the column STILL holds the estimate (only a release() ever
     *    decrements it, and no release ran), so re-stamping the key merely restores the
     *    invariant "key present <=> column holds this estimate". Without it, the later
     *    release() would pull null, skip the decrement, and the reservation would be stranded
     *    on the account FOREVER (spendable credit permanently lost).
     *
     * CALL CONTRACT: only while the asset is still non-terminal, under its row lock — the same
     * lock the terminal release runs under. That serialises renew against release, so a renew
     * can never resurrect a key that a release has already claimed (which would double-decrement).
     */
    public function renew(Reservation $reservation): void
    {
        $this->cache->put(
            $this->cacheKey($reservation->id),
            $reservation->estimateMicroUsd,
            $this->ttl(),
        );
    }

    /** True while the in-flight reservation is still held (the cache key exists). */
    public function isHeld(string $idempotencyKey): bool
    {
        return $this->cache->has($this->cacheKey($idempotencyKey));
    }

    /**
     * Atomically move reserved_micro_usd by $delta under a row lock, clamped at 0
     * (a reservation can never push reserved below zero). Runs in a transaction so
     * concurrent reserves/releases serialize on the account row.
     */
    private function adjustReserved(Account $account, int $delta): void
    {
        DB::transaction(function () use ($account, $delta): void {
            /** @var Account $locked */
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $next = max(0, $locked->reserved_micro_usd + $delta);
            $locked->forceFill([self::RESERVED_COLUMN => $next])->save();

            // Keep the passed-in instance in sync for the caller.
            $account->setAttribute(self::RESERVED_COLUMN, $next);
        });
    }

    private function cacheKey(string $idempotencyKey): string
    {
        return self::CACHE_PREFIX.$idempotencyKey;
    }

    private function ttl(): int
    {
        return (int) config(self::TTL_CONFIG_KEY);
    }
}
