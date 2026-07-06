<?php

namespace App\Filament\Merchant\Concerns;

use App\Models\Account;
use App\Models\Site;
use Filament\Facades\Filament;
use RuntimeException;

/**
 * ResolvesShopAccount — the account for a merchant-panel surface is the CURRENT SHOP
 * TENANT's account, never the auth user's.
 *
 * The merchant panel is shop-centric (Filament tenant = Site), so the tenant is the
 * source of truth for the account context: a normal owner's shop belongs to their own
 * account, while a super-admin drilled into a shop carries NO own account (or a
 * different one) — reading Auth::user()->account there is null/wrong (a TypeError 500,
 * or another account's figures). Filament::getTenant() is the account-safe source (the
 * same pattern EndUserResource + TryOnHistory already use), and BindMerchantAccount has
 * already bound THIS account for the request.
 */
trait ResolvesShopAccount
{
    /** The current shop tenant's account — account-scoped + drill-in-safe. */
    protected function shopAccount(): Account
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Site) {
            throw new RuntimeException('No shop tenant is bound for this merchant request.');
        }

        return $tenant->account;
    }
}
