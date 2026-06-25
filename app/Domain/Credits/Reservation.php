<?php

namespace App\Domain\Credits;

/**
 * Reservation — a handle to credit held in-flight for one generation.
 *
 * Reserving holds the estimated MAX charge so two concurrent generations cannot
 * both pass the gate against the same balance. The reservation lives in two
 * places (see ReservationManager): the accounts.reserved_micro_usd column (the
 * durable, transactional truth the gate reads) and a short-lived Redis lock keyed
 * on the idempotency key (the in-flight window guard). This object only carries
 * the identity + amount + which account it belongs to; the manager does the I/O.
 *
 * The reservation id is DETERMINISTIC — it is the generation idempotency key — so
 * a retry of the same generation re-derives the same reservation and never
 * double-reserves.
 */
final readonly class Reservation
{
    public function __construct(
        public int $accountId,
        public string $id,
        public int $estimateMicroUsd,
    ) {}

    /** Build a reservation tied to a generation's deterministic idempotency key. */
    public static function forKey(int $accountId, string $idempotencyKey, int $estimateMicroUsd): self
    {
        return new self($accountId, $idempotencyKey, $estimateMicroUsd);
    }
}
