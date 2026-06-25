<?php

namespace App\Domain\Credits\Payments;

/**
 * PurchaseIntent — the typed result of initiating a top-up. The provider created a
 * hosted payment page (or link); the merchant is redirected to redirectUrl to pay.
 *
 * This is the OUTBOUND half of the rail: nothing is credited yet. The credit_ledger
 * `purchase` row is only written later, by the idempotent webhook, on a confirmed
 * `paid` event. providerRef is the provider's page/transaction id and is the third
 * segment of the idempotency key purchase:{account}:{provider}:{provider_ref}.
 */
final readonly class PurchaseIntent
{
    private function __construct(
        public bool $ok,
        public string $provider,
        public ?string $providerRef,
        public ?string $redirectUrl,
        public int $amountMicroUsd,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    /** The provider created the payment page; redirect the merchant to pay. */
    public static function created(
        string $provider,
        string $providerRef,
        string $redirectUrl,
        int $amountMicroUsd,
    ): self {
        return new self(
            ok: true,
            provider: $provider,
            providerRef: $providerRef,
            redirectUrl: $redirectUrl,
            amountMicroUsd: $amountMicroUsd,
            errorCode: null,
            errorMessage: null,
        );
    }

    /** The provider could not create the page (transport / config / rejection). */
    public static function failed(string $provider, string $errorCode, string $errorMessage): self
    {
        return new self(
            ok: false,
            provider: $provider,
            providerRef: null,
            redirectUrl: null,
            amountMicroUsd: 0,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
