<?php

namespace App\Domain\Platform;

use App\Exceptions\PlatformAccessRequiredException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformGuard — the single assertion behind every audited cross-account read seam.
 *
 * The control-plane queries (PlatformSiteQuery / PlatformCreditLedgerQuery /
 * PlatformActivityQuery) are the ONLY places a BelongsToAccount global scope is
 * bypassed in product code. Each one is unusable unless this guard passes: the
 * authenticated user must be a confirmed super-admin (User::isSuperAdmin()), resolved
 * from Auth ONLY — never from the request body — so a client can never unlock a seam
 * by input. A non-super-admin (or unauthenticated) caller fails LOUD here rather than
 * quietly reaching the cross-account builder.
 */
final class PlatformGuard
{
    /**
     * The authenticated user must be a confirmed super-admin, else fail loud. Called
     * at the entry of every platform seam BEFORE any withoutGlobalScope() runs.
     */
    public static function assert(): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            throw PlatformAccessRequiredException::make();
        }
    }
}
