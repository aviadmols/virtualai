<?php

namespace App\Domain\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AccountProvisioner — the ONE atomic "create a merchant Account + its account-owner
 * User" core.
 *
 * Both onboarding entry points funnel through here so there is exactly one
 * account-creation shape in the app: the super-admin control plane
 * (PlatformAccountProvisioner) and the Shopify-SSO auto-provision
 * (ShopifyAccountProvisioner). The opening credit grant is written by
 * AccountObserver::created (never here); account + owner are created in a SINGLE
 * transaction, so a failed owner insert can never leave a half-provisioned account (or a
 * lone opening grant) behind.
 *
 * This core performs NO authorization: each caller gates access in its own context (the
 * PlatformGuard for the control plane, the HMAC-verified OAuth callback for Shopify).
 */
final class AccountProvisioner
{
    /**
     * Create the tenant account and its non-super-admin owner user, atomically.
     *
     * The owner password is passed as PLAINTEXT — the User model's `hashed` cast hashes
     * it on write (never double-hash). `email_verified` marks the owner verified when the
     * email came from a trusted source (e.g. Shopify).
     *
     * @param  array{name:string,company_name?:string|null,billing_email?:string|null,locale?:string}  $account
     * @param  array{name:string,email:string,password:string,email_verified?:bool}  $owner
     */
    public function create(array $account, array $owner): ProvisionedAccount
    {
        return DB::transaction(static function () use ($account, $owner): ProvisionedAccount {
            $model = Account::create([
                'name' => $account['name'],
                'company_name' => $account['company_name'] ?? null,
                'billing_email' => $account['billing_email'] ?? null,
                'locale' => $account['locale'] ?? Account::DEFAULT_LOCALE,
            ]);

            $user = User::create([
                'account_id' => $model->getKey(),
                'is_super_admin' => false,
                'name' => $owner['name'],
                'email' => $owner['email'],
                'password' => $owner['password'],
            ]);

            // email_verified_at is not fillable (verification is a trust decision, not a
            // form field): stamp it explicitly when the caller vouches for the email.
            if (($owner['email_verified'] ?? false) === true) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return new ProvisionedAccount($model, $user);
        });
    }
}
