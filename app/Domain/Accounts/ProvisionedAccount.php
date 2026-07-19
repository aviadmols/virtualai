<?php

namespace App\Domain\Accounts;

use App\Models\Account;
use App\Models\User;

/**
 * ProvisionedAccount — the result of AccountProvisioner::create(): the new tenant
 * Account and its account-owner User, created atomically. A value object so a caller
 * that needs to log the owner in (Shopify SSO) gets the user without re-querying.
 */
final class ProvisionedAccount
{
    public function __construct(
        public readonly Account $account,
        public readonly User $owner,
    ) {}
}
