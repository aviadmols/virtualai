<?php

namespace App\Domain\Platform;

use App\Domain\Accounts\AccountProvisioner;
use App\Models\Account;

/**
 * PlatformAccountProvisioner — the audited super-admin path to create a merchant
 * account together with its owner login user.
 *
 * Self-service sign-up is not the only onboarding path: a super-admin provisions an
 * account from the control plane. Guarded by PlatformGuard (super-admin only); the
 * account + owner creation itself is delegated to the shared AccountProvisioner (account
 * + owner in ONE transaction; the opening $5 grant is written by AccountObserver::created
 * — never here), so the control plane and the Shopify-SSO path share ONE creation shape.
 */
final class PlatformAccountProvisioner
{
    public function __construct(
        private readonly AccountProvisioner $accounts,
    ) {}

    /**
     * Provision an account + its owner user atomically. Returns the new account.
     *
     * The owner password is passed as plaintext — the shared core's User `hashed` cast
     * hashes it on write. The owner is a normal (non-super-admin) account user who signs
     * in to the merchant panel.
     *
     * @param  array<string,mixed>  $data
     */
    public function provision(array $data): Account
    {
        PlatformGuard::assert();

        return $this->accounts->create(
            account: [
                'name' => $data['name'],
                'company_name' => $data['company_name'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'locale' => $data['locale'] ?? Account::DEFAULT_LOCALE,
            ],
            owner: [
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
            ],
        )->account;
    }
}
