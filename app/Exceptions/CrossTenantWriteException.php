<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-owned model is created with an explicit account_id that
 * does not match the currently-bound tenant.
 *
 * Isolation is a release blocker on writes as well as reads: a bound tenant
 * must never be able to stamp a row for a DIFFERENT account (a cross-tenant
 * write). The BelongsToAccount creating hook raises this instead of silently
 * persisting the foreign id.
 */
final class CrossTenantWriteException extends RuntimeException
{
    public static function for(string $model, int $boundAccountId, int $attemptedAccountId): self
    {
        return new self(sprintf(
            'Cross-tenant write blocked: cannot create %s for account_id %d while tenant %d is bound.',
            $model,
            $attemptedAccountId,
            $boundAccountId,
        ));
    }
}
