<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the audited platform-admin cross-account read seam is invoked
 * outside a platform (super-admin) context.
 *
 * The global-scope bypass exists in exactly ONE place (PlatformSiteQuery) and is
 * gated on a confirmed super-admin. Reaching that bypass without one is a
 * release-blocker-class isolation breach, so it fails LOUD here rather than
 * quietly returning cross-tenant data.
 */
final class PlatformAccessRequiredException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Platform-admin access required: the cross-account site query is usable '.
            'only by an authenticated super-admin.',
        );
    }
}
