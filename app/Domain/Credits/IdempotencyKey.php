<?php

namespace App\Domain\Credits;

/**
 * IdempotencyKey — the deterministic ledger/idempotency keys (ARCHITECTURE.md
 * "Idempotency keys — never random"). Same inputs ALWAYS produce the same key, so
 * a duplicate write collides on the credit_ledger unique index.
 *
 * Every key carries account_id as its first segment after the prefix, so a key can
 * never be mistaken across accounts (the tenancy audit checks this).
 *
 *   charge:      generation:{account}:{site}:{end_user}:{product}:{sha1(variant)}:{client_request_id}
 *   refund:      refund:{account}:{generation}
 *   grant:       grant:{account}:{slug}           (opening grant slug = "opening")
 *   adjustment:  adjustment:{account}:{slug}      (a deterministic admin slug)
 *   purchase:    purchase:{account}:{provider}:{provider_ref}  (saas-credits-billing writes it)
 */
final class IdempotencyKey
{
    // === CONSTANTS ===
    private const PREFIX_GENERATION = 'generation';
    private const PREFIX_BANNER = 'banner';
    private const PREFIX_REFUND = 'refund';
    private const PREFIX_GRANT = 'grant';
    private const PREFIX_ADJUSTMENT = 'adjustment';
    private const PREFIX_PURCHASE = 'purchase';

    // The opening-grant slug — one opening grant per account, ever.
    public const OPENING_GRANT_SLUG = 'opening';

    /**
     * The charge key for a generation. Collapses widget double-clicks (the
     * client_request_id is the last, stable segment) AND queue retries (the rest
     * is fully determined by the generation identity + the selected variant).
     */
    public static function forGeneration(
        int $accountId,
        int $siteId,
        int $endUserId,
        int $productId,
        array|string $variant,
        string $clientRequestId,
    ): string {
        return implode(':', [
            self::PREFIX_GENERATION,
            $accountId,
            $siteId,
            $endUserId,
            $productId,
            self::hashVariant($variant),
            $clientRequestId,
        ]);
    }

    /**
     * The charge key for a banner generation attempt. Collapses a double-clicked Generate
     * (same client_request_id) AND a queue retry (the rest is fixed by the banner identity).
     * A NEW Generate carries a new client_request_id, so it mints a fresh asset — the merchant
     * iterating on candidates is not deduped, only an accidental repeat of one click is.
     */
    public static function forBanner(
        int $accountId,
        int $siteId,
        int $bannerId,
        string $clientRequestId,
    ): string {
        return implode(':', [
            self::PREFIX_BANNER,
            $accountId,
            $siteId,
            $bannerId,
            $clientRequestId,
        ]);
    }

    /** The refund key for a generation. One refund per charged generation. */
    public static function forRefund(int $accountId, int $generationId): string
    {
        return implode(':', [self::PREFIX_REFUND, $accountId, $generationId]);
    }

    /** A grant key. The opening grant uses OPENING_GRANT_SLUG (idempotent per account). */
    public static function forGrant(int $accountId, string $slug): string
    {
        return implode(':', [self::PREFIX_GRANT, $accountId, $slug]);
    }

    /** An adjustment key — a deterministic admin slug makes a re-run idempotent. */
    public static function forAdjustment(int $accountId, string $slug): string
    {
        return implode(':', [self::PREFIX_ADJUSTMENT, $accountId, $slug]);
    }

    /** A purchase key (saas-credits-billing writes purchase rows through the ledger). */
    public static function forPurchase(int $accountId, string $provider, string $providerRef): string
    {
        return implode(':', [self::PREFIX_PURCHASE, $accountId, $provider, $providerRef]);
    }

    /**
     * sha1 of the selected variant — a canonical (key-sorted) JSON so the same
     * variant always hashes the same regardless of attribute order.
     */
    public static function hashVariant(array|string $variant): string
    {
        if (is_string($variant)) {
            return sha1($variant);
        }

        $canonical = $variant;
        ksort($canonical);

        return sha1((string) json_encode($canonical));
    }
}
