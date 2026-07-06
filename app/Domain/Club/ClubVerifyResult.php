<?php

namespace App\Domain\Club;

/**
 * ClubVerifyResult — the typed outcome of a one-time-code verification.
 *
 * A verify call is a business outcome, never an exception: the widget renders a
 * screen per case (verified / try again / expired / locked out). The stable
 * `reason` string is what the endpoint returns alongside verified:false so the
 * widget i18n catalog can localize it.
 */
enum ClubVerifyResult: string
{
    // The code matched — the shopper is now a verified club member.
    case Verified = 'verified';

    // No pending code, or the code is wrong (attempt consumed, still under cap).
    case Invalid = 'invalid';

    // The code expired (TTL passed) or was never issued.
    case Expired = 'expired';

    // Too many wrong attempts — the code is burned; request a new one.
    case Locked = 'locked';

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
