<?php

namespace App\Domain\Credits\Payments;

/**
 * PurchaseResult — the typed, SIGNATURE-VERIFIED outcome of a provider webhook.
 *
 * verifyAndParseWebhook() returns one of these only AFTER the signature passed; an
 * unverifiable/forged webhook returns null (the controller no-ops it). The trust
 * boundary lives here: amountMicroUsd is the PROVIDER-CONFIRMED amount (parsed from
 * the signed body), never a client-reported figure. status maps the provider's
 * transaction state to our credit_purchases status machine.
 *
 * accountId is resolved from our OWN persisted credit_purchases row (keyed by the
 * provider_ref), never trusted from the webhook body — so a webhook can never credit
 * an account it was not initiated for.
 */
final readonly class PurchaseResult
{
    // === CONSTANTS ===
    // The provider-state -> our credit_purchases status mapping.
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
    ];

    private function __construct(
        public string $provider,
        public string $providerRef,
        public string $status,
        public int $amountMicroUsd,
        public array $raw,
    ) {}

    /**
     * Build a verified result. $status MUST be one of STATUSES (the provider
     * implementation normalises its own codes into ours before calling this).
     */
    public static function make(
        string $provider,
        string $providerRef,
        string $status,
        int $amountMicroUsd,
        array $raw = [],
    ): self {
        return new self(
            provider: $provider,
            providerRef: $providerRef,
            status: in_array($status, self::STATUSES, true) ? $status : self::STATUS_FAILED,
            amountMicroUsd: max(0, $amountMicroUsd),
            raw: $raw,
        );
    }

    /** A confirmed, paid top-up — the only result that writes a `purchase` ledger row. */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
