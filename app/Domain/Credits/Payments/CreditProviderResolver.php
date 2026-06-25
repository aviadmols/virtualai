<?php

namespace App\Domain\Credits\Payments;

use App\Models\Account;
use RuntimeException;

/**
 * CreditProviderResolver — picks the CreditPaymentProvider for an account. v1 is
 * LOCKED to PayPlus; this is the single seam a future Stripe (or per-region) rail
 * plugs into without touching the initiator/webhook call sites.
 *
 * Resolution is by provider NAME so the credit_purchases.provider stored on a pending
 * row can later be re-resolved for the webhook (the webhook route is per-provider, but
 * verifying through the resolved provider keeps it swappable).
 */
final class CreditProviderResolver
{
    // === CONSTANTS ===
    public const DEFAULT_PROVIDER = PayPlusProvider::PROVIDER_NAME;

    /** @param array<string, CreditPaymentProvider> $providers keyed by provider name */
    public function __construct(
        private readonly array $providers,
    ) {}

    /** The provider for an account. v1: always the locked default (PayPlus). */
    public function for(Account $account): CreditPaymentProvider
    {
        return $this->byName(self::DEFAULT_PROVIDER);
    }

    /** A provider by its name (the webhook route resolves the one it serves). */
    public function byName(string $name): CreditPaymentProvider
    {
        $provider = $this->providers[$name] ?? null;

        if ($provider === null) {
            throw new RuntimeException("No CreditPaymentProvider registered for [{$name}].");
        }

        return $provider;
    }
}
