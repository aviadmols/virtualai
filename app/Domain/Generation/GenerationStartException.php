<?php

namespace App\Domain\Generation;

use RuntimeException;

/**
 * GenerationStartException — a generation could not even START (a 4xx-class input
 * problem the widget must fix), distinct from a generation that started and then
 * failed at the model step (which is a typed failure on the row, never an exception).
 *
 * Thrown by StartGeneration for: missing use-my-photo consent, a product that is not
 * confirmed (only a confirmed product is generation-eligible), or a variant that does
 * not belong to the product. Carries a stable reason the HTTP layer maps to a message.
 */
final class GenerationStartException extends RuntimeException
{
    // === CONSTANTS ===
    public const REASON_PHOTO_CONSENT_REQUIRED = 'photo_consent_required';

    public const REASON_PRODUCT_NOT_CONFIRMED = 'product_not_confirmed';

    public const REASON_VARIANT_MISMATCH = 'variant_mismatch';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function photoConsentRequired(): self
    {
        return new self(self::REASON_PHOTO_CONSENT_REQUIRED, 'Use-my-photo consent is required to generate a try-on.');
    }

    public static function productNotConfirmed(): self
    {
        return new self(self::REASON_PRODUCT_NOT_CONFIRMED, 'Only a confirmed product is eligible for a try-on.');
    }

    public static function variantMismatch(): self
    {
        return new self(self::REASON_VARIANT_MISMATCH, 'The selected variant does not belong to the product.');
    }
}
