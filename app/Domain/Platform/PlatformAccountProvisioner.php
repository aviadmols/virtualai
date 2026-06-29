<?php

namespace App\Domain\Platform;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PlatformAccountProvisioner — the audited super-admin path to create a merchant
 * account together with its owner login user.
 *
 * Self-service sign-up is not the only onboarding path: a super-admin provisions an
 * account from the control plane. Account is the tenant ROOT (not BelongsToAccount) and
 * User is on GlobalModels::ALLOW_LIST, so both create cleanly with NO tenant bound. The
 * opening $5 grant is written by AccountObserver::created — never here. Guarded by
 * PlatformGuard (super-admin only); account + owner are created in ONE transaction so a
 * failed user insert can never leave a half-provisioned account behind.
 */
final class PlatformAccountProvisioner
{
    /**
     * Provision an account + its owner user atomically. Returns the new account.
     *
     * The owner password is passed as plaintext — the User model's `hashed` cast hashes
     * it on write (never double-hash here). account_id stamps the new account; the owner
     * is a normal (non-super-admin) account user who signs in to the merchant panel.
     *
     * @param  array<string,mixed>  $data
     */
    public function provision(array $data): Account
    {
        PlatformGuard::assert();

        return DB::transaction(static function () use ($data): Account {
            $account = Account::create([
                'name' => $data['name'],
                'company_name' => $data['company_name'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'locale' => $data['locale'] ?? Account::DEFAULT_LOCALE,
            ]);

            User::create([
                'account_id' => $account->getKey(),
                'is_super_admin' => false,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
            ]);

            return $account;
        });
    }
}
